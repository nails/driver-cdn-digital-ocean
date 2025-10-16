<?php

namespace Nails\Cdn\Driver;

use Aws\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Exception;
use Nails\Cdn\Exception\DriverException;
use Nails\Common\Exception\EnvironmentException;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Service\FileCache;
use Nails\Environment;
use Nails\Factory;
use stdClass;

class DigitalOcean extends Local
{
    /**
     * The S3 SDK
     */
    protected S3Client $oSdk;

    /**
     * The Digital Ocean DataCenter where the space is stored
     */
    protected string $sDoDataCenter = '';

    /**
     * The Digital Ocean Space where items will be stored
     */
    protected string $sDoSpace = '';

    // --------------------------------------------------------------------------

    /**
     * Returns an instance of the AWS S3 SDK
     *
     * @return S3Client
     * @throws DriverException
     */
    protected function sdk(): S3Client
    {
        if (empty($this->oSdk)) {
            $this->oSdk = new S3Client([
                'version'     => 'latest',
                'endpoint'    => 'https://' . $this->getDataCenter() . '.digitaloceanspaces.com',
                'region'      => $this->getDataCenter(),
                'credentials' => new Credentials(
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
     * @throws DriverException
     */
    protected function getSpace(): string
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
     * @throws DriverException
     */
    protected function getDataCenter(): string
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
     * @return stdClass
     * @throws DriverException
     */
    protected function getDataCenterAndSpace(): stdClass
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
     * @throws DriverException
     */
    protected function getUri(string $sUriType): string
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
     * @param stdClass $oData Data to create the object with
     */
    public function objectCreate(stdClass $oData): bool
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

            //  Create a "normal" version
            $this->sdk()->putObject([
                'Bucket'      => $this->getSpace(),
                'Key'         => $sBucket . '/' . $sFilename . $sExtension,
                'SourceFile'  => $sSource,
                'ContentType' => $sMime,
                'ACL'         => 'public-read',
            ]);

        } catch (Exception $e) {
            $this->setError('DO-SDK EXCEPTION: [objectCreate:put]: ' . $e->getMessage());
            return false;
        }

        try {

            //  Create a "download" version
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

        } catch (Exception $e) {
            $this->setError('DO-SDK EXCEPTION: [objectCreate:copy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether an object exists or not
     *
     * @param string $sFilename The object's filename
     * @param string $sBucket   The bucket's slug
     */
    public function objectExists(string $sFilename, string $sBucket): bool
    {
        try {

            return $this->sdk()->doesObjectExist(
                $this->getBucket(),
                $sBucket . '/' . $sFilename
            );

        } catch (Exception $e) {
            $this->setError('DO-SDK EXCEPTION: [objectExists]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Move an object
     *
     * @param string $sSourceObject The source object's filename
     * @param string $sSourceBucket The source bucket's slug
     * @param string $sTargetObject The target object's filename
     * @param string $sTargetBucket The target bucket's slug
     */
    public function objectMove(
        string $sSourceObject,
        string $sSourceBucket,
        string $sTargetObject,
        string $sTargetBucket
    ): bool {
        try {

            throw new Exception('The Digital Ocean CDN driver does not support moving objects.');

        } catch (Exception $e) {
            $this->setError('DO-SDK EXCEPTION: [objectMove]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Copy an object
     *
     * @param string $sSourceObject The source object's filename
     * @param string $sSourceBucket The source bucket's slug
     * @param string $sTargetObject The target object's filename
     * @param string $sTargetBucket The target bucket's slug
     */
    public function objectCopy(
        string $sSourceObject,
        string $sSourceBucket,
        string $sTargetObject,
        string $sTargetBucket
    ): bool {
        try {

            throw new Exception('The Digital Ocean CDN driver does not support copying objects.');

        } catch (Exception $e) {
            $this->setError('DO-SDK EXCEPTION: [objectCopy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Destroys (permanently deletes) an object
     *
     * @param string $sObject The object's filename
     * @param string $sBucket The bucket's slug
     */
    public function objectDestroy(string $sObject, string $sBucket): bool
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

        } catch (Exception $e) {
            $this->setError('DO-SDK EXCEPTION: [objectDestroy]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a local path for an object
     *
     * @param string $sBucket   The bucket's slug
     * @param string $sFilename The filename
     *
     * @return bool|string String on success, false on failure
     * @throws FactoryException
     * @throws DriverException
     */
    public function objectLocalPath(string $sBucket, string $sFilename): bool|string
    {
        /** @var FileCache $oFileCache */
        $oFileCache = Factory::service('FileCache');

        //  Do we have the original source file?
        $sExtension = strtolower(substr($sFilename, strrpos($sFilename, '.')));
        $sFilename  = strtolower(substr($sFilename, 0, strrpos($sFilename, '.')));
        $sSrcFile   = $oFileCache->getDir() . $sBucket . '-' . $sFilename . '-SRC' . $sExtension;

        //  Check filesystem for a source file
        if (file_exists($sSrcFile)) {
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
                $this->setError('DO-SDK EXCEPTION: [objectLocalPath]: ' . $e->getMessage());
                return false;
            }
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether an object's meta data is set correctly or not
     *
     * @param string $sFilename        The object's filename
     * @param string $sFilenameDisplay The object's human-friendly name
     * @param string $sBucket          The bucket's slug
     * @param string $sMimeType        The object's mime type
     *
     * @return string[]
     */
    public function getObjectMetaDataErrors(
        string $sFilename,
        string $sFilenameDisplay,
        string $sBucket,
        string $sMimeType
    ): array {

        $aErrors = [];

        try {

            $sExtension = strtolower(substr($sFilename, strrpos($sFilename, '.') + 1));
            $sFilename  = strtolower(substr($sFilename, 0, strrpos($sFilename, '.')));

            $oNormalObject = $this->sdk()->headObject([
                'Bucket' => $this->getBucket(),
                'Key'    => sprintf('%s/%s.%s', $sBucket, $sFilename, $sExtension),
            ]);

            $oExpected = json_encode([
                'ContentType' => $sMimeType,
            ]);

            $oActual = json_encode([
                'ContentType' => $oNormalObject->get('ContentType'),
            ]);

            if ($oExpected !== $oActual) {
                $aErrors[] = sprintf('Incorrect content type for normal object. (Expected: %s, Actual: %s)', $oExpected, $oActual);
            }

            $oDownloadObject = $this->sdk()->headObject([
                'Bucket' => $this->getBucket(),
                'Key'    => sprintf('%s/%s-download.%s', $sBucket, $sFilename, $sExtension),
            ]);

            $oExpected = json_encode([
                'ContentType'        => 'application/octet-stream',
                'ContentDisposition' => sprintf('attachment; filename="%s"', $sFilenameDisplay),
            ]);

            $oActual = json_encode([
                'ContentType'        => $oDownloadObject->get('ContentType'),
                'ContentDisposition' => $oDownloadObject->get('ContentDisposition'),
            ]);

            if ($oExpected !== $oActual) {
                $aErrors[] = sprintf('Incorrect content type for download object. (Expected: %s, Actual: %s)', $oExpected, $oActual);
            }

        } catch (\Exception $e) {
            $sMessage  = 'AWS-SDK EXCEPTION: [getObjectMetaDataErrors]: ' . $e->getMessage();
            $aErrors[] = $sMessage;
            $this->setError($sMessage);
        }

        return $aErrors;
    }

    // --------------------------------------------------------------------------

    /**
     * Attempt to fix object meta data
     *
     * @param string $sFilename        The object's filename
     * @param string $sFilenameDisplay The object's human-friendly name
     * @param string $sBucket          The bucket's slug
     * @param string $sMimeType        The object's mime type
     *
     * @return bool
     */
    public function fixObjectMetaDataErrors(
        string $sFilename,
        string $sFilenameDisplay,
        string $sBucket,
        string $sMimeType
    ): bool {
        try {

            $sExtension = strtolower(substr($sFilename, strrpos($sFilename, '.') + 1));
            $sFilename  = strtolower(substr($sFilename, 0, strrpos($sFilename, '.')));

            $this->sdk()->copyObject([
                'Bucket'            => $this->getBucket(),
                'CopySource'        => sprintf('%s/%s/%s.%s', $this->getBucket(), $sBucket, $sFilename, $sExtension),
                'Key'               => sprintf('%s/%s.%s', $sBucket, $sFilename, $sExtension),
                'ContentType'       => $sMimeType,
                'MetadataDirective' => 'REPLACE',
            ]);

            $this->sdk()->copyObject([
                'Bucket'             => $this->getBucket(),
                'CopySource'         => sprintf('%s/%s/%s-download.%s', $this->getBucket(), $sBucket, $sFilename, $sExtension),
                'Key'                => sprintf('%s/%s-download.%s', $sBucket, $sFilename, $sExtension),
                'ContentType'        => 'application/octet-stream',
                'ContentDisposition' => 'attachment; filename="' . str_replace('"', '', $sFilenameDisplay) . '"',
                'MetadataDirective'  => 'REPLACE',
            ]);

            return true;

        } catch (\Exception $e) {
            $this->setError('AWS-SDK EXCEPTION: [fixObjectMetaDataErrors]: ' . $e->getMessage());
            return false;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * BUCKET METHODS
     */

    /**
     * Creates a new bucket
     *
     * @param string $sBucket The bucket's slug
     *
     * @throws DriverException
     */
    public function bucketCreate(string $sBucket): bool
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

            } catch (Exception $e) {
                $this->setError('DO-SDK EXCEPTION: [bucketCreate]: ' . $e->getMessage());
                return false;
            }

        } else {
            return true;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes an existing bucket
     *
     * @param string $sBucket The bucket's slug
     */
    public function bucketDestroy(string $sBucket): bool
    {
        //  @todo (Pablo - 2018-07-24) - Consider the implications of bucket deletion; maybe prevent deletion of non-empty buckets
        try {

            $this->sdk()->deleteMatchingObjects($this->getSpace(), $sBucket . '/');
            return true;

        } catch (Exception $e) {
            $this->setError('DO-SDK EXCEPTION: [bucketDestroy]: ' . $e->getMessage());
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
     * @param string $sObject The object to serve
     * @param string $sBucket The bucket to serve from
     */
    public function urlServeRaw(string $sObject, string $sBucket): string
    {
        return $this->urlServe($sObject, $sBucket);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the scheme of 'serve' URLs
     *
     * @param bool $bForceDownload Whether to force download
     *
     * @throws DriverException
     */
    public function urlServeScheme(bool $bForceDownload = false): string
    {
        $sUrl = addTrailingSlash($this->getUri('serve')) . '{{bucket}}/';

        /**
         * If we're forcing the download, we need to reference a slightly different file.
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
     * @param string $sBucket        The bucket which the image resides in
     * @param string $sObject        The object to be served
     * @param int    $iExpires       The length of time the URL should be valid for, in seconds
     * @param bool   $bForceDownload Whether to force a download
     *
     * @throws FactoryException
     * @throws EnvironmentException
     */
    public function urlExpiring(string $sObject, string $sBucket, int $iExpires, bool $bForceDownload = false): string
    {
        //  @todo (Pablo - 2018-07-24) - Implement DO's expiring URL system
        return parent::urlExpiring($sObject, $sBucket, $iExpires, $bForceDownload);
    }
}
