# Changelog

All notable changes to FieldtypeFileB2 will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-12-02

### 🎉 Major Release: Large File Support

This release adds support for files up to 700MB with automatic chunked uploads.

### Added
- **Chunked Upload System**: Automatic chunked uploads for files ≥ 50MB
  - Files are split into 10MB chunks
  - Reliable upload for large video files
  - Progress logging for each chunk
- **Smart Upload Selection**: Module automatically chooses best method
  - Files < 50MB: Standard upload (fast, single request)
  - Files ≥ 50MB: Chunked upload (reliable, memory-efficient)
- **Comprehensive Logging**: Detailed progress tracking
  - File size in MB shown in logs
  - Chunk progress (e.g., "Uploaded chunk 15/50")
  - Success/failure notifications
- **Documentation**: Complete server optimization guide
  - Nginx configuration examples
  - PHP settings recommendations
  - Troubleshooting section
  - Cloudflare setup guide

### Changed
- **Module Version**: 9 → 10
- **Upload Threshold**: Changed from 100MB to 50MB for chunked uploads
  - More reliable for medium-large files
  - Better memory management
  - Faster recovery from failures
- **Error Messages**: More descriptive error reporting
  - HTTP status codes included
  - Specific chunk numbers on failure
  - Better debugging information

### Improved
- **Memory Efficiency**: Chunked reading reduces RAM usage
  - Reads 10MB at a time instead of entire file
  - Prevents memory exhausted errors
  - Works with 512MB PHP memory limit
- **Reliability**: Better handling of network issues
  - Each chunk can be retried independently
  - Timeout-resistant for slow connections
  - Progress tracking prevents "stuck" uploads

### Technical Details
- Uses B2 Large File API for files ≥ 50MB
- Chunk size: 10MB (optimal for network stability)
- Maximum tested file size: 700MB
- Theoretical limit: 970GB (B2 API limit: 10,000 chunks × 100MB)

### Server Requirements
- **PHP**: 7.4+ (8.3 recommended)
- **PHP Settings**:
  - `max_execution_time`: 1800 (30 minutes)
  - `max_input_time`: 1800
  - `memory_limit`: 512MB minimum
  - `upload_max_filesize`: 2GB
  - `post_max_size`: 2GB
- **Nginx**:
  - `client_max_body_size`: 2048M
  - `client_body_timeout`: 1800s
  - `fastcgi_read_timeout`: 1800
  - `fastcgi_send_timeout`: 1800

### Migration Guide

**From v0.0.8 to v1.0.0:**

1. **Backup your module:**
   ```bash
   cp -r /site/modules/FieldtypeFileB2 /site/modules/FieldtypeFileB2.backup
   ```

2. **Update module files:**
   - Replace `InputfieldFileB2.module.php`
   - Keep your existing configuration

3. **Update server settings** (CRITICAL for files >50MB):
   
   **PHP Settings (CloudPanel → Sites → PHP Settings):**
   ```
   max_execution_time: 1800
   max_input_time: 1800
   memory_limit: 512M
   upload_max_filesize: 2GB
   post_max_size: 2GB
   ```

   **Nginx Vhost (CloudPanel → Sites → Vhost):**
   ```nginx
   # Add to both server blocks:
   client_max_body_size 2048M;
   client_body_timeout 1800s;
   
   # Add to PHP location block:
   fastcgi_read_timeout 1800;
   fastcgi_send_timeout 1800;
   ```

4. **Refresh modules:**
   - ProcessWire Admin → Modules → Refresh
   - Verify version shows "10"

5. **Test uploads:**
   - Small file (< 50MB) - should use standard upload
   - Large file (> 50MB) - should use chunked upload
   - Check logs for progress messages

### Breaking Changes
- **None** - Fully backward compatible with v0.0.8
- Existing files continue to work
- Module settings unchanged
- API remains the same

---

## [0.0.9] - 2025-11-30

### Added
- Initial chunked upload implementation (100MB threshold)
- Basic error handling for large files

### Fixed
- File size tracking issues
- Memory exhaustion on large files

---

## [0.0.8] - 2025-11-15

### Added
- Custom domain support
- Improved URL generation
- Added `b2url` property hook
- Better error handling

### Changed
- File size tracking method
- URL generation logic

### Fixed
- File deletion issues
- Metadata storage problems

---

## [0.0.7] - 2025-11-01

### Added
- Support for file descriptions
- Drag-and-drop sorting
- AJAX upload progress

### Fixed
- ProcessWire field schema issues
- Database primary key conflicts

---

## [0.0.6] - 2025-10-15

### Added
- Backblaze B2 Large File API support
- Basic chunked upload (experimental)

### Changed
- Authentication method
- Error reporting

---

## [0.0.5] - 2025-10-01

### Added
- Custom domain configuration
- SSL support toggle
- Cache control headers

---

## [0.0.4] - 2025-09-15

### Fixed
- Upload timeout issues
- Memory limit problems
- Bucket ID validation

---

## [0.0.3] - 2025-09-01

### Added
- Multiple file support
- File metadata storage
- Tags support

---

## [0.0.2] - 2025-08-15

### Fixed
- Authentication errors
- File upload failures
- URL generation bugs

---

## [0.0.1] - 2025-08-01

### Added
- Initial release
- Basic B2 upload functionality
- ProcessWire field type
- Standard file upload support

---

## Version Numbering

We follow Semantic Versioning:
- **MAJOR** version for incompatible API changes
- **MINOR** version for new functionality (backward compatible)
- **PATCH** version for bug fixes (backward compatible)

Current version: **1.0.0** (First stable release)
