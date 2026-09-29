# Mega Server Fetch

**Server-side file downloader for cPanel and shared hosting.**

Server Fetch lets you paste a **Mega.nz link or a normal direct HTTP/HTTPS download URL** and transfer the file directly to your hosting server.

The file does **not** need to pass through your computer.

> Paste link → Server downloads → File is saved on your hosting account

## ✨ Features

- 📥 Download Mega.nz files directly to your server
- 🌐 Download files from normal HTTP/HTTPS URLs
- ☁️ Works with Cloudflare R2, CDN and other public direct-download URLs
- 🔐 Password-protected interface
- 📊 Live download progress
- ⚡ Displays download speed
- ⏱️ Shows estimated time remaining
- ⛔ Cancel active downloads
- 📁 Browse downloaded files
- 🔗 Copy/open downloaded file links
- 🗑️ Delete downloaded files
- 💾 Shows available server disk space
- 📱 Responsive mobile-friendly interface
- 🌙 Automatic light/dark mode
- 🛡️ Blocks execution of downloaded PHP and other server-side script files
- 🚫 Prevents directory listing
- 🧩 No database required

## 🧰 Requirements

Server Fetch is designed for typical cPanel/shared-hosting environments.

### Required

- PHP 7+
- PHP cURL extension
- PHP OpenSSL extension
- Writable hosting directory

These extensions are normally available on modern cPanel hosting environments.

## 🚀 Installation

### 1. Download `fetch.php`

Upload `fetch.php` to your hosting account, normally:

```text
public_html/
└── fetch.php
```

### 2. Set your password

Open `fetch.php` and find:

```php
$PASSWORD = 'change-this-password';
```

Change it to a strong private password:

```php
$PASSWORD = 'your-strong-password';
```

**Do not leave the default password.**

### 3. Open the downloader

Visit:

```text
https://yourdomain.com/fetch.php
```

Enter your password.

### 4. Paste a download URL

You can use either:

```text
https://mega.nz/file/...
```

or a normal direct URL:

```text
https://example.com/files/file.zip
```

The application attempts to identify the filename and file size before starting the download.

### 5. Download directly to the server

Click:

**Download to server**

The file is downloaded directly into the configured destination directory.

By default:

```php
$DEST_DIR = __DIR__ . '/data';
```

So the structure becomes:

```text
public_html/
├── fetch.php
└── data/
    └── downloaded-file.zip
```

## 📂 Using `shared` Instead of `data`

If you prefer a folder named `shared`, change:

```php
$DEST_DIR = __DIR__ . '/data';
```

to:

```php
$DEST_DIR = __DIR__ . '/shared';
```

The folder is automatically created when necessary.

## 🧠 How Mega Downloads Work

Mega files are encrypted and cannot simply be downloaded using an ordinary HTTP request.

Server Fetch handles the Mega link by:

1. Reading the file handle and decryption key from the Mega URL.
2. Contacting Mega's API.
3. Obtaining the temporary download address.
4. Obtaining the encrypted file metadata.
5. Decrypting the file while it is being downloaded.
6. Writing the decrypted file directly to your hosting server.

The decryption therefore happens on the server.

Your computer acts only as the browser interface.

## 🔄 Download Process

```text
                ┌─────────────────┐
                │   Paste URL     │
                └────────┬────────┘
                         │
                         ▼
                ┌─────────────────┐
                │  Server Fetch   │
                └────────┬────────┘
                         │
              ┌──────────┴──────────┐
              │                     │
              ▼                     ▼
        ┌───────────┐         ┌────────────┐
        │   Mega    │         │ Direct URL │
        └─────┬─────┘         └──────┬─────┘
              │                      │
              ▼                      │
       Mega API +                    │
       decryption                    │
              │                      │
              └──────────┬───────────┘
                         ▼
                  ┌──────────────┐
                  │ Server Disk  │
                  └──────────────┘
                         │
                         ▼
                    Downloaded
                       File
```

## 📊 Progress Tracking

Large downloads are handled as background-style jobs.

The interface can display:

- Download percentage
- Bytes downloaded
- Total size
- Download speed
- Estimated remaining time
- Current state
- Errors
- Cancellation status

Job information is stored temporarily in:

```text
.fetch_jobs/
```

Old job files are automatically cleaned up.

## 🔒 Security

The application includes several protections because it downloads files from URLs supplied by a user.

### Password protection

All API operations require the configured password.

### Public-host validation

The application checks that remote hosts resolve to public addresses rather than private/internal network addresses.

### Restricted file types

Downloaded files with potentially executable extensions such as:

```text
.php
.phtml
.phar
.cgi
.pl
.py
.sh
```

are prevented from being saved with their executable extension.

For example:

```text
shell.php
```

may become:

```text
shell.php.txt
```

### Download directory protection

A `.htaccess` file is automatically created in the download directory to disable directory listing and prevent execution of PHP-like files.

## ⚠️ Important Security Advice

This tool can write files onto your hosting account.

**Do not expose it publicly without password protection.**

Use a strong password and do not share the `fetch.php` URL and password unnecessarily.

After finishing a one-time transfer, consider deleting:

```text
fetch.php
```

from your server.

This is especially important on shared hosting.

## 📦 Mega Limitations

Currently supported:

- Individual Mega file links

Not supported:

- Mega folder links
- Mega folder trees

For a folder, open the folder in Mega and provide the URL of an individual file instead.

## 🌐 Direct URL Support

Normal HTTP/HTTPS URLs are also supported.

Examples include direct files hosted on:

- Cloudflare R2
- CDN servers
- Web servers
- Public file servers
- Other publicly accessible HTTP/HTTPS locations

The URL must be accessible from the hosting server.

## ⏳ Shared Hosting Limitations

Shared hosting providers may impose limits that are outside the application's control.

Large downloads can be affected by:

- PHP execution limits
- Hosting CPU limits
- Hosting bandwidth limits
- Disk quotas
- Mega transfer quotas
- Network interruptions
- Server resource restrictions

The script requests unlimited PHP execution time, but the hosting provider may enforce its own maximum execution time.

For very large files, SSH/cPanel Terminal with tools such as `wget` or Mega-specific command-line utilities may be more reliable.

## 🗂️ Recommended Server Structure

```text
public_html/
│
├── fetch.php
│
├── data/
│   ├── .htaccess
│   └── downloaded-files
│
└── .fetch_jobs/
    ├── .htaccess
    └── temporary-job-information
```

The application creates the required directories automatically.

## 🖥️ Interface

The interface is intentionally contained in the same PHP file.

It includes:

- Responsive CSS
- Light/dark mode
- Download progress animation
- Mobile layout
- JavaScript API communication
- File management controls

No external frontend framework or database is required.

## 🛠️ Configuration

The main configuration is near the beginning of `fetch.php`:

```php
$PASSWORD    = 'change-this-password';
$DEST_DIR    = __DIR__ . '/data';
$JOBS_DIR    = __DIR__ . '/.fetch_jobs';
```

Change the password and destination directory according to your hosting setup.

## 🧪 Example

Suppose your domain is:

```text
example.com
```

You upload:

```text
fetch.php
```

to:

```text
public_html/
```

Then visit:

```text
https://example.com/fetch.php
```

Paste:

```text
https://mega.nz/file/XXXXXXXX#XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

Server Fetch retrieves the file from Mega, decrypts it while downloading, and saves the resulting file inside:

```text
public_html/data/
```

Your desktop is not used as the file-transfer destination.

## 📜 License

This project is provided as-is for personal and server-management use.

Review and adapt the code, security configuration and hosting restrictions before using it in a production environment.

## ⚠️ Disclaimer

Only download files that you have permission to access and store.

The software itself does not grant access to private or restricted files. It simply transfers files accessible through URLs supplied to it.
