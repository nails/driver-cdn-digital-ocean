<?php

namespace Nails\Cdn\Driver;

use Aws\Common\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Nails\Cdn\Exception\DriverException;
use Nails\Common\Service\FileCache;
use Nails\Environment;
use Nails\Factory;

class DigitalOcean extends Local
{
    /**
     * The S3 SDK
     *
     * @var S3Client
     */
    protected $oSdk;

    /**
     * The Digital Ocean DataCenter where the spaces is stored
     *
     * @var string
     */
    protected $sDoDataCenter;

    /**
     * The Digital Ocean Space where items will be stored
     *
     * @var string
     */
    protected $sDoSpace;

    // --------------------------------------------------------------------------

    /**
     * Returns an instance of the AWS S3 SDK
     *
     * @return S3Client
     */
    protected function sdk()
    {
        if (empty($this->oSdk)) {
            $this->oSdk = new \Aws\S3\S3Client([
                'version'     => 'latest',
                'endpoint'    => 'https://' . $this->getDataCenter() . '.digitaloceanspaces.com',
                'region'      => $this->getDataCenter(),
                'credentials' => new \Aws\Credentials\Credentials(
                    $this->getSetting('access_key'),
                    $this->getSetting('access_secret')
                ),
            ]);
        }

        return $this->oSdk;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the Digital Ocean Space for this environment
     *
     * @return string
     *
     * @throws DriverException
     */
    protected function getSpace()
    {
        if (empty($this->sDoSpace)) {
            $this->sDoSpace = $this->getDataCenterAndSpace()->space;
            if (empty($this->sDoSpace)) {
                throw new DriverException('Digital Ocean Space has not been defined.');
            }
        }

        return $this->sDoSpace;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the Data center to use
     *
     * @return string
     *
     * @throws DriverException
     */
    protected function getDataCenter()
    {
        if (empty($this->sDoDataCenter)) {
            $this->sDoDataCenter = $this->getDataCenterAndSpace()->data_center;
            if (empty($this->sDoDataCenter)) {
                throw new DriverException('Digital Ocean Data Center has not been defined.');
            }
        }

        return $this->sDoDataCenter;
    }

    // --------------------------------------------------------------------------

    /**
     * Extracts the Data Center and Space from the configs
     *
     * @return \stdClass
     *
     * @throws DriverException
     */
    protected function getDataCenterAndSpace()
    {
        $aSpaces = json_decode($this->getSetting('spaces'), true);
        if (empty($aSpaces)) {
            throw new DriverException('Digital Ocean Spaces have not been defined.');
        } elseif (empty($aSpaces[Environment::get()])) {
            throw new DriverException('No space defined for the ' . Environment::get() . ' environment.');
        } else {
            $sDataCenterSpace = explode(':', $aSpaces[Environment::get()]);
            return (object) [
                'data_center' => getFromArray(0, $sDataCenterSpace, ''),
                'space'       => getFromArray(1, $sDataCenterSpace, ''),
            ];
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the requested URI and replaces {{space}} and {{data_center}} with value from settings
     *
     * @param string $sUriType The type of URI which is being generated
     *
     * @return string
     */
    protected function getUri($sUriType)
    {
        return str_replace(
            ['{{space}}', '{{data_center}}'],
            [$this->getSpace(), $this->getDataCenter()],
            $this->getSetting('uri_' . $sUriType)
        );
    }

    // --------------------------------------------------------------------------

    /**
     * OBJECT METHODS
     */

    /**
     * Creates a new object
     *
     * @param  \stdClass $oData Data to create the object with
     *
     * @return boolean
     */
    public function objectCreate($oData)
    {
        $sBucket       = !empty($oData->bucket->slug) ? $oData->bucket->slug : '';
        $sFilenameOrig = !empty($oData->filename) ? $oData->filename : '';
        $sFilename     = strtolower(substr($sFilenameOrig, 0, strrpos($sFilenameOrig, '.')));
        $sExtension    = strtolower(substr($sFilenameOrig, strrpos($sFilenameOrig, '.')));
        $sSource       = !empty($oData->file) ? $oData->file : '';
        $sMime         = !empty($oData->mime) ? $oData->mime : '';
        $sName         = !empty($oData->name) ? $oData->name : 'file' . $sExtension;

        // --------------------------------------------------------------------------

        try {

            //  Create "normal" version
            $this->sdk()->putObject([
                'Bucket'      => $this->getSpace(),
                'Key'         => $sBucket . '/' . $sFilename . $sExtension,
                'SourceFile'  => $sSource,
                'ContentType' => $sMime,
                'ACL'         => 'public-read',
            ]);

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [objectCreate:put]: ' . $e->getMessage());
            return false;
        }

        try {

            //  Create "download" version
            $this->sdk()->copyObject([
                'Bucket'             => $this->getSpace(),
                'CopySource'         => $this->getSpace() . '/' . $sBucket . '/' . $sFilename . $sExtension,
                'Key'                => $sBucket . '/' . $sFilename . '-download' . $sExtension,
                'ContentType'        => 'application/octet-stream',
                'ContentDisposition' => 'attachment; filename="' . str_replace('"', '', $sName) . '" ',
                'MetadataDirective'  => 'REPLACE',
                'ACL'                => 'public-read',
            ]);

            return true;

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [objectCreate:copy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether an object exists or not
     *
     * @param  string $sFilename The object's filename
     * @param  string $sBucket   The bucket's slug
     *
     * @return boolean
     */
    public function objectExists($sFilename, $sBucket)
    {
        return $this->sdk()->doesObjectExist($sBucket, $sFilename);
    }

    // --------------------------------------------------------------------------

    /**
     * Destroys (permanently deletes) an object
     *
     * @param  string $sObject The object's filename
     * @param  string $sBucket The bucket's slug
     *
     * @return boolean
     */
    public function objectDestroy($sObject, $sBucket)
    {
        try {

            $sFilename  = strtolower(substr($sObject, 0, strrpos($sObject, '.')));
            $sExtension = strtolower(substr($sObject, strrpos($sObject, '.')));
            $this->sdk()->deleteObjects([
                'Bucket' => $this->getSpace(),
                'Delete' => [
                    'Objects' => [
                        ['Key' => $sBucket . '/' . $sFilename . $sExtension],
                        ['Key' => $sBucket . '/' . $sFilename . '-download' . $sExtension],
                    ],
                ],
            ]);
            return true;

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [objectDestroy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a local path for an object
     *
     * @param  string $sBucket   The bucket's slug
     * @param  string $sFilename The filename
     *
     * @return mixed             String on success, false on failure
     */
    public function objectLocalPath($sBucket, $sFilename)
    {
        /** @var FileCache $oFileCache */
        $oFileCache = Factory::service('FileCache');

        //  Do we have the original sourcefile?
        $sExtension = strtolower(substr($sFilename, strrpos($sFilename, '.')));
        $sFilename  = strtolower(substr($sFilename, 0, strrpos($sFilename, '.')));
        $sSrcFile   = $oFileCache->getDir() . $sBucket . '-' . $sFilename . '-SRC' . $sExtension;

        //  Check filesystem for source file
        if (file_exists($sSrcFile)) {

            //  Yup, it's there, so use it
            return $sSrcFile;

        } else {

            //  Doesn't exist, attempt to fetch from S3
            try {

                $this->sdk()->getObject([
                    'Bucket' => $this->getSpace(),
                    'Key'    => $sBucket . '/' . $sFilename . $sExtension,
                    'SaveAs' => $sSrcFile,
                ]);

                return $sSrcFile;

            } catch (S3Exception $e) {

                //  Clean up
                if (file_exists($sSrcFile)) {
                    unlink($sSrcFile);
                }

                //  Note the error
                $this->setError('AWS-SDK EXCEPTION: [objectLocalPath]: ' . $e->getMessage());
                return false;
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * BUCKET METHODS
     */

    /**
     * Creates a new bucket
     *
     * @param  string $sBucket The bucket's slug
     *
     * @return boolean
     */
    public function bucketCreate($sBucket)
    {
        //  Attempt to create a 'folder' object on S3
        if (!$this->sdk()->doesObjectExist($this->getSpace(), $sBucket . '/')) {

            try {

                $this->sdk()->putObject([
                    'Bucket' => $this->getSpace(),
                    'Key'    => $sBucket . '/',
                    'Body'   => '',
                ]);

                return true;

            } catch (\Exception $e) {
                $this->setError('AWS-SDK EXCEPTION: [bucketCreate]: ' . $e->getMessage());
                return false;
            }

        } else {

            //  Bucket already exists.
            return true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an existing bucket
     *
     * @param  string $sBucket The bucket's slug
     *
     * @return boolean
     */
    public function bucketDestroy($sBucket)
    {
        //  @todo (Pablo - 2018-07-24) - Consider the implications of bucket deletion; maybe prevent deletion of non-empty buckets
        try {

            $this->sdk()->deleteMatchingObjects($this->getSpace(), $sBucket . '/');
            return true;

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [bucketDestroy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * URL GENERATOR METHODS
     */

    /**
     * Generate the correct URL for serving a file direct from the file system
     *
     * @param  string $sObject The object to serve
     * @param  string $sBucket The bucket to serve from
     *
     * @return string
     */
    public function urlServeRaw($sObject, $sBucket)
    {
        return $this->urlServe($sObject, $sBucket);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the scheme of 'serve' URLs
     *
     * @param  boolean $bForceDownload Whether or not to force download
     *
     * @return string
     */
    public function urlServeScheme($bForceDownload = false)
    {
        $sUrl = addTrailingSlash($this->getUri('serve')) . '{{bucket}}/';

        /**
         * If we're forcing the download we need to reference a slightly different file.
         * On upload two instances were created, the "normal" streaming type one and
         * another with the appropriate Content-Types set so that the browser downloads
         * as opposed to renders it
         */
        if ($bForceDownload) {
            $sUrl .= '{{filename}}-download{{extension}}';
        } else {
            $sUrl .= '{{filename}}{{extension}}';
        }

        return $this->urlMakeSecure($sUrl, false);
    }

    // --------------------------------------------------------------------------

    /**
     * Generates a properly hashed expiring url
     *
     * @param  string  $sBucket        The bucket which the image resides in
     * @param  string  $sObject        The object to be served
     * @param  integer $iExpires       The length of time the URL should be valid for, in seconds
     * @param  boolean $bForceDownload Whether to force a download
     *
     * @return string
     */
    public function urlExpiring($sObject, $sBucket, $iExpires, $bForceDownload = false)
    {
        //  @todo (Pablo - 2018-07-24) - Implement DO's expiring URL system
        return parent::urlExpiring($sObject, $sBucket, $iExpires, $bForceDownload);
    }
}
