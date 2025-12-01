# FieldtypeFileB2 for ProcessWire

Store and serve files directly from Backblaze B2 Cloud Storage in ProcessWire. Perfect for video hosting, large media files, and reducing server storage costs.

## Features

- 🚀 **Direct B2 Upload** - Files upload directly to Backblaze B2
- 💰 **Cost Effective** - $6/TB/month (5x cheaper than AWS S3)
- 🌐 **Custom Domain Support** - Use your own CDN domain
- ⚡ **Cloudflare Integration** - Free bandwidth via Bandwidth Alliance
- 🔒 **Public & Private Buckets** - Flexible access control
- 📦 **Multiple Files** - Support for repeater fields with multiple files
- 🎬 **Perfect for Video** - Tested with Plyr, Video.js and other players

## Requirements

- ProcessWire 3.x
- PHP 7.4+
- Backblaze B2 Account
- cURL extension enabled

## Installation

1. Download and extract to `/site/modules/FieldtypeFileB2/`
2. Go to **Modules → Refresh**
3. Install **FieldtypeFileB2**

## Configuration

### 1. Create Backblaze B2 Account

1. Sign up at [backblaze.com](https://www.backblaze.com/b2/sign-up.html)
2. Enable B2 Cloud Storage
3. Create a bucket (Public or Private)
4. Generate Application Key

### 2. Module Configuration

**Setup → Modules → Configure → InputfieldFileB2**
```
Backblaze Key ID: [Your Key ID]
Backblaze Application Key: [Your Application Key]
Bucket Name: your-bucket-name
Bucket ID: [Your Bucket ID]
Bucket Type: Public (or Private)
✅ Use SSL
❌ Store files locally (uncheck!)
Cache-Control max-age: 86400 (24 hours)
```

### 3. Create Field

1. **Setup → Fields → Add New Field**
2. Type: **FieldtypeFileB2**
3. Name: `b2_video` (or any name)
4. Add to your template

### 4. Optional: Custom Domain with Cloudflare

For free bandwidth and faster delivery:

**A. DNS Setup**

Add CNAME record:
```
Type: CNAME
Name: cdn
Value: f005.backblazeb2.com (use your region)
Proxy: Enabled (orange cloud)
```

**B. Cloudflare Transform Rule**

**Rules → Transform Rules → Create URL Rewrite Rule:**
```
Rule name: B2 CDN Path Rewrite

If incoming requests match:
  Field: Hostname
  Operator: equals
  Value: cdn.yourdomain.com

Then rewrite the path:
  Dynamic: concat("/file/YOUR-BUCKET-NAME", http.request.uri.path)
```

**C. Enable Custom Domain in Module**
```
✅ Use Custom Domain
Custom Domain: cdn.yourdomain.com
```

## Usage Examples

### Basic Usage
```php
<?php namespace ProcessWire;

// Single file
<?php if($page->b2_video): ?>
<video controls>
    <source src="<?= $page->b2_video->url ?>" type="video/mp4">
</video>
<?php endif; ?>

// Multiple files
<?php foreach($page->b2_video as $video): ?>
<video controls>
    <source src="<?= $video->url ?>" type="video/mp4">
</video>
<?php endforeach; ?>
```

### With Custom Domain (b2url property)
```php
<?php foreach($page->b2_video as $video): ?>
<video controls>
    <source src="<?= $video->b2url ?>" type="video/mp4">
</video>
<?php endforeach; ?>
```

### With Plyr Player
```html
<link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css">

<?php foreach($page->b2_video as $video): ?>
<video class="plyr-video" playsinline controls>
    <source src="<?= $video->b2url ?>" type="video/mp4">
</video>
<?php endforeach; ?>

<script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
<script>
    Plyr.setup('.plyr-video', {
        controls: ['play-large', 'play', 'progress', 'current-time', 
                   'mute', 'volume', 'fullscreen']
    });
</script>
```

### In Repeater Fields
```php
<?php foreach($page->videos as $item): ?>
    <h3><?= $item->title ?></h3>
    
    <?php foreach($item->b2_video as $video): ?>
    <video controls>
        <source src="<?= $video->b2url ?>" type="video/mp4">
    </video>
    <?php endforeach; ?>
<?php endforeach; ?>
```

## Pricing Comparison

### Backblaze B2 + Cloudflare
- Storage: **$6/TB/month**
- Bandwidth: **$0** (free via Cloudflare)
- Transactions: **Free** (first 2,500/day)

**Example:** 500 GB storage + 5 TB bandwidth = **$3/month**

### AWS S3 (for comparison)
- Storage: **$23/TB/month**
- Bandwidth: **$90/TB**
- Transactions: **$0.005 per 1,000**

**Same usage:** 500 GB storage + 5 TB bandwidth = **$461.50/month**

💰 **You save 99% on bandwidth with Cloudflare!**

## CORS Configuration

For cross-domain requests, add CORS rules in B2:
```json
[
  {
    "corsRuleName": "downloadFromAnyOrigin",
    "allowedOrigins": ["https://yourdomain.com"],
    "allowedOperations": ["b2_download_file_by_name"],
    "allowedHeaders": ["*"],
    "exposeHeaders": ["*"],
    "maxAgeSeconds": 3600
  }
]
```

## Troubleshooting

### Files return 404

1. Check if `localStorage` is disabled in module settings
2. Verify bucket is Public (or CORS configured for Private)
3. Confirm files uploaded successfully to B2

### Custom domain not working

1. Ensure Cloudflare Proxy is **enabled** (orange cloud)
2. Verify Transform Rule is **deployed** (not draft)
3. Wait 1-2 minutes for DNS propagation
4. Check Transform Rule uses correct bucket name

### Video not playing

1. Verify MP4 codec compatibility (H.264 video, AAC audio)
2. Check CORS configuration
3. Confirm file uploaded with correct Content-Type

## Best Practices

1. **Use Custom Domain with Cloudflare** for free bandwidth
2. **Enable Cloudflare Caching** for faster delivery
3. **Set appropriate Cache-Control** headers (24-48 hours for videos)
4. **Use Public buckets** for simpler setup (unless security required)
5. **Disable localStorage** to use B2 storage

## Cloudflare Caching Optimization

**Page Rules:**
```
URL: cdn.yourdomain.com/*
Settings:
  - Cache Level: Cache Everything
  - Edge Cache TTL: 1 month
  - Browser Cache TTL: 4 hours
```

This will:
- Reduce B2 API calls to near zero
- Speed up video delivery worldwide
- Maximize free bandwidth usage

## License

MIT License - feel free to use commercially

## Credits

Developed for video hosting on [lqrs.com](https://lqrs.com)

## Support

For issues and feature requests, please use GitHub Issues.

## Changelog

### Version 0.0.8
- Added custom domain support
- Improved URL generation
- Added b2url property hook
- Fixed file size tracking
- Better error handling
