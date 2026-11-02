# Generic S3 Upload API (CodeIgniter 4)

A generic, secure S3 upload API service built on CodeIgniter 4 (PHP 8.1+) and the official AWS SDK for PHP (`aws/aws-sdk-php`). Multiple client projects can upload media and assets by supplying their own AWS credentials, bucket details, and target paths per request.

---

## Features

- **Per-request AWS Credentials**: Dynamic `S3Client` instantiation using request-supplied credentials.
- **Disk Streaming**: File uploads are streamed straight from disk (`ObjectUploader`) rather than buffered in PHP memory, allowing files up to 500 MB (or configured limit).
- **Automatic MIME Detection**: Sets the S3 `ContentType` using detected file MIME types.
- **Path Traversal Protection**: Comprehensive sanitization of folders and filenames (`<sanitized path>/<uuid>_<sanitized original filename>`).
- **Bearer Token Authentication**: Enforced via `ApiTokenFilter` with timing-safe comparison (`hash_equals`).
- **Rate Limiting**: IP-based rate limiting (30 requests/minute) using CodeIgniter cache.
- **HTTPS Enforcement**: Automatic rejection/redirect of insecure HTTP requests in production.
- **Zero Credential Leaks**: Credentials and request bodies are never logged; error responses return AWS error codes only.
- **Consistent JSON Error Handling**: All HTTP errors (including 404, 500) return JSON responses with debug stack traces suppressed in production.

---

## Requirements

- PHP 8.1 or higher
- PHP Extensions: `curl`, `intl`, `mbstring`, `fileinfo`, `json`, `simplexml`
- Composer 2.x

---

## Quick Setup

1. **Install Dependencies**:
   ```bash
   composer install
   ```

2. **Configure Environment**:
   Copy `.env.example` to `.env` and configure your settings:
   ```bash
   cp .env.example .env
   ```

   Key `.env` settings:
   ```ini
   CI_ENVIRONMENT = production
   app.baseURL = 'https://yourdomain.com/'
   app.forceGlobalSecureRequests = true

   # Secret token required in Authorization: Bearer <token>
   API_TOKEN = your-secure-token-here

   # Max upload size in MB (default: 500)
   MAX_UPLOAD_MB = 500
   ```

3. **Start Development Server** (for local testing):
   ```bash
   php spark serve
   ```

4. **Run Unit Tests**:
   ```bash
   vendor/bin/phpunit --no-coverage
   ```

---

## API Specification

### Endpoint

```http
POST /api/upload
Content-Type: multipart/form-data
Authorization: Bearer <API_TOKEN>
```

### Request Fields

| Field        | Type   | Required | Description                                                    |
|--------------|--------|----------|----------------------------------------------------------------|
| `file`       | File   | Yes      | File to upload (video, image, document, any type).             |
| `bucket`     | String | Yes      | S3 bucket name.                                                |
| `region`     | String | Yes      | AWS region (e.g. `ap-south-1`, `us-east-1`).                   |
| `access_key` | String | Yes      | AWS Access Key ID.                                             |
| `secret_key` | String | Yes      | AWS Secret Access Key.                                         |
| `path`       | String | No       | Folder prefix in bucket (e.g. `clips/` or `uploads/2026/`).    |
| `acl`        | String | No       | S3 ACL: `private` or `public-read`. Omit if ACLs are disabled. |

### Example cURL Request

```bash
curl -X POST https://yourdomain.com/api/upload \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@video.mp4" \
  -F "bucket=my-bucket" \
  -F "region=ap-south-1" \
  -F "access_key=AKIAIOSFODNN7EXAMPLE" \
  -F "secret_key=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY" \
  -F "path=clips/" \
  -F "acl=public-read"
```

### Response Formats

#### 1. Success (`200 OK`)
```json
{
  "success": true,
  "key": "clips/550e8400-e29b-41d4-a716-446655440000_video.mp4",
  "url": "https://my-bucket.s3.ap-south-1.amazonaws.com/clips/550e8400-e29b-41d4-a716-446655440000_video.mp4"
}
```

#### 2. Unauthorized (`401 Unauthorized`)
```json
{
  "success": false,
  "error": "Unauthorized: Invalid API token."
}
```

#### 3. Validation Failure (`422 Unprocessable Entity`)
```json
{
  "success": false,
  "error": "Validation failed",
  "messages": {
    "bucket": "The bucket field is required.",
    "file": "The file size exceeds the allowed limit of 500 MB."
  }
}
```

#### 4. AWS Error (`400 Bad Request`)
```json
{
  "success": false,
  "error": "NoSuchBucket"
}
```
*Note: Credentials and full request payloads are never logged or returned.*

#### 5. Rate Limit Exceeded (`429 Too Many Requests`)
```json
{
  "success": false,
  "error": "Too Many Requests",
  "message": "Rate limit of 30 requests per minute exceeded."
}
```

---

## Important S3 Object Ownership & ACL Note

> [!WARNING]
> If your S3 bucket has **Object Ownership** set to **"Bucket owner enforced"** (AWS's default recommendation), **ACLs are disabled**.
> 
> Sending `acl=public-read` to such a bucket will result in an `AccessControlListNotSupported` or `InvalidBucketAclWithObjectOwnership` error from AWS.
> 
> In that case:
> - Omit the `acl` parameter in the request.
> - Configure public access using an **S3 Bucket Policy** or serve files securely through **Amazon CloudFront**.

---

## Production Deployment & Large Upload Configuration

To allow uploads up to 500 MB, verify that PHP and your web server are configured accordingly:

### 1. `php.ini` Settings
Ensure the following values in your `php.ini`:
```ini
upload_max_filesize = 500M
post_max_size = 510M
max_execution_time = 600
max_input_time = 600
memory_limit = 256M
```
*(Note: Because file streaming is used directly from disk, `memory_limit` does not need to equal the file size.)*

### 2. Nginx Configuration
In your Nginx server block:
```nginx
server {
    listen 443 ssl http2;
    server_name yourdomain.com;
    root /path/to/project/public;

    # Allow up to 510M bodies
    client_max_body_size 510M;

    # Increase proxy / fastcgi timeouts for large files
    client_body_timeout 600s;
    fastcgi_read_timeout 600s;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 3. Apache Configuration
If using Apache, set `LimitRequestBody` in your virtual host or `.htaccess`:
```apache
LimitRequestBody 534773760
```
And verify `mod_rewrite` is enabled.
