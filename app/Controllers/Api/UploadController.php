<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use Aws\Exception\AwsException;
use Aws\Exception\MultipartUploadException;
use Aws\S3\Exception\S3MultipartUploadException;
use Aws\S3\ObjectUploader;
use Aws\S3\S3Client;
use Config\Upload as UploadConfig;
use Throwable;

class UploadController extends BaseController
{
    /**
     * Handle generic multipart file upload to AWS S3.
     */
    public function upload()
    {
        // Check for content-length exceeding PHP's post_max_size
        $contentLength = (int) ($this->request->getServer('CONTENT_LENGTH') ?? 0);
        if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
            return $this->response
                ->setStatusCode(422)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => 'Payload exceeds the server post_max_size limit.',
                ]);
        }

        // Determine max allowed upload size in MB
        $uploadConfig = config(UploadConfig::class);
        $maxSizeMb = (int) (env('MAX_UPLOAD_MB') ?: ($uploadConfig->maxUploadSizeMb ?? 500));
        $maxSizeBytes = $maxSizeMb * 1024 * 1024;

        // Validation rules for scalar parameters
        $rules = [
            'bucket'     => [
                'label' => 'bucket',
                'rules' => 'required|string|trim',
            ],
            'region'     => [
                'label' => 'region',
                'rules' => 'required|string|trim',
            ],
            'access_key' => [
                'label' => 'access_key',
                'rules' => 'required|string|trim',
            ],
            'secret_key' => [
                'label' => 'secret_key',
                'rules' => 'required|string|trim',
            ],
            'path'       => [
                'label' => 'path',
                'rules' => 'permit_empty|string|trim',
            ],
            'acl'        => [
                'label' => 'acl',
                'rules' => 'permit_empty|in_list[private,public-read]',
            ],
        ];

        $errors = [];
        if (! $this->validate($rules)) {
            $errors = $this->validator->getErrors();
        }

        // Validate the uploaded file
        $file = $this->request->getFile('file');
        if ($file === null || ! $file->isValid()) {
            $errors['file'] = $file !== null ? $file->getErrorString() : 'The file field is required.';
        } elseif ($file->getSize() > $maxSizeBytes) {
            $errors['file'] = "The file size exceeds the allowed limit of {$maxSizeMb} MB.";
        }

        if (! empty($errors)) {
            return $this->response
                ->setStatusCode(422)
                ->setContentType('application/json')
                ->setJSON([
                    'success'  => false,
                    'error'    => 'Validation failed',
                    'messages' => $errors,
                ]);
        }

        $bucket    = (string) $this->request->getPost('bucket');
        $region    = (string) $this->request->getPost('region');
        $accessKey = (string) $this->request->getPost('access_key');
        $secretKey = (string) $this->request->getPost('secret_key');
        $path      = $this->request->getPost('path');
        $acl       = $this->request->getPost('acl');

        $aclValue = ($acl !== null && trim((string) $acl) !== '') ? trim((string) $acl) : null;

        // Build sanitized S3 Key: <sanitized path>/<uuid>_<sanitized original filename>
        $sanitizedPath     = $this->sanitizePath($path !== null ? (string) $path : null);
        $sanitizedFilename = $this->sanitizeFilename($file->getClientName());
        $uuid              = $this->generateUuid();

        $keyBasename = "{$uuid}_{$sanitizedFilename}";
        $s3Key       = $sanitizedPath !== '' ? "{$sanitizedPath}/{$keyBasename}" : $keyBasename;

        // Detect MIME Content-Type
        $contentType = $file->getMimeType();
        if (empty($contentType)) {
            $contentType = 'application/octet-stream';
        }

        $fileStream = null;

        try {
            // Instantiate S3Client per request with provided credentials
            $s3Client = new S3Client([
                'version'     => 'latest',
                'region'      => $region,
                'credentials' => [
                    'key'    => $accessKey,
                    'secret' => $secretKey,
                ],
            ]);

            // Stream file directly from disk without buffering full file into memory
            $tempPath = $file->getTempName();
            $fileStream = fopen($tempPath, 'rb');

            if ($fileStream === false) {
                return $this->response
                    ->setStatusCode(500)
                    ->setContentType('application/json')
                    ->setJSON([
                        'success' => false,
                        'error'   => 'CannotOpenFileStream',
                    ]);
            }

            // Configure ObjectUploader options
            $uploaderOptions = [
                'params' => [
                    'ContentType' => $contentType,
                ],
            ];

            if ($aclValue !== null) {
                $uploaderOptions['params']['ACL'] = $aclValue;
            }

            $uploader = new ObjectUploader(
                $s3Client,
                $bucket,
                $s3Key,
                $fileStream,
                $aclValue,
                $uploaderOptions
            );

            // Execute upload
            $uploader->upload();

            $url = sprintf('https://%s.s3.%s.amazonaws.com/%s', $bucket, $region, $s3Key);

            return $this->response
                ->setStatusCode(200)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => true,
                    'key'     => $s3Key,
                    'url'     => $url,
                ]);
        } catch (S3MultipartUploadException|MultipartUploadException $e) {
            $previous = $e->getPrevious();
            $awsCode  = ($previous instanceof AwsException && ! empty($previous->getAwsErrorCode()))
                ? $previous->getAwsErrorCode()
                : 'MultipartUploadFailed';

            log_message('error', 'S3 Multipart upload failed: ' . $awsCode);

            return $this->response
                ->setStatusCode(400)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => $awsCode,
                ]);
        } catch (AwsException $e) {
            $awsCode = $e->getAwsErrorCode() ?: 'AwsError';

            log_message('error', 'S3 upload failed: ' . $awsCode);

            return $this->response
                ->setStatusCode(400)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => $awsCode,
                ]);
        } catch (Throwable $e) {
            log_message('error', 'Unexpected upload failure occurred');

            return $this->response
                ->setStatusCode(500)
                ->setContentType('application/json')
                ->setJSON([
                    'success' => false,
                    'error'   => 'UploadFailed',
                ]);
        } finally {
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }
        }
    }

    /**
     * Sanitize path by stripping "../", leading/trailing slashes, and unsafe characters.
     */
    protected function sanitizePath(?string $path): string
    {
        if ($path === null) {
            return '';
        }

        // Normalize slashes
        $path = str_replace('\\', '/', $path);

        // Strip path traversal attempts
        $path = str_replace('../', '', $path);
        $path = str_replace('..', '', $path);

        // Allow alphanumeric, underscores, hyphens, slashes, and periods
        $path = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', $path);

        // Trim slashes and collapse consecutive slashes
        $path = trim($path, '/');
        $path = preg_replace('#/+#', '/', $path);

        $segments = explode('/', $path);
        $cleanSegments = [];

        foreach ($segments as $segment) {
            $segment = trim($segment, '.');
            if ($segment !== '') {
                $cleanSegments[] = $segment;
            }
        }

        return implode('/', $cleanSegments);
    }

    /**
     * Sanitize original filename preserving clean extension.
     */
    protected function sanitizeFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));

        $dotPos = strrpos($basename, '.');
        if ($dotPos !== false) {
            $name = substr($basename, 0, $dotPos);
            $ext  = substr($basename, $dotPos + 1);
        } else {
            $name = $basename;
            $ext  = '';
        }

        $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
        $cleanName = preg_replace('/_+/', '_', $cleanName);
        $cleanName = trim($cleanName, '_');

        if ($cleanName === '') {
            $cleanName = 'file';
        }

        $cleanExt = preg_replace('/[^a-zA-Z0-9]/', '', $ext);

        return $cleanExt !== '' ? "{$cleanName}.{$cleanExt}" : $cleanName;
    }

    /**
     * Generate RFC 4122 compliant UUID v4 string.
     */
    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

