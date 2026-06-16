<?php namespace ProcessWire;

/**
 * An Inputfield for handling file uploads to Backblaze B2
 * 
 * @property string $b2KeyId Backblaze Application Key ID
 * @property string $b2ApplicationKey Backblaze Application Key
 * @property string $bucketName Name of the B2 bucket
 * @property string $bucketId ID of the B2 bucket
 * @property string $bucketType Type of bucket (allPublic or allPrivate)
 * @property bool $useSSL Use HTTPS for file URLs
 * @property bool $useCustomDomain Use custom domain for serving files
 * @property string $customDomain Custom domain name
 * @property bool $localStorage Store files locally instead of B2
 * @property int $cacheControl Cache-Control max-age in seconds
 */
class InputfieldFileB2 extends InputfieldFile implements Module {

	// B2 API session data
	private $authToken = null;
	private $apiUrl = null;
	private $downloadUrl = null;
	private $b2AccountId = null;

	public static function getModuleInfo() {
		return array(
			'title' => __('InputfieldFileB2', __FILE__),
			'summary' => __('One or more file uploads to Backblaze B2 (sortable)', __FILE__),
			'author' => 'Maxim Semenov',
			'href'     => 'https://smnv.org',
			'version' => 110, // 1.1.0
			'autoload' => true
		);
	}

	public function init() {
		parent::init();
		
		// Load config file
		$configFile = dirname(__FILE__) . '/InputfieldFileB2Config.php';
		if(file_exists($configFile)) {
			require_once($configFile);
		}
		
		// Add hook for b2url property (property access only — method call not needed)
		$this->addHookProperty('Pagefile::b2url', $this, 'hookB2Url');
		
		// Add hook to delete local files after page save (only if not using localStorage)
		$this->addHookAfter('Pages::saved', $this, 'hookPageSaved');
	}
	
	public function renderReady(Inputfield $parent = null, $renderValueMode = false) {
		$modules = $this->wire('modules');
		
		$inputfieldFile = $modules->get('InputfieldFile');
		if($inputfieldFile) {
			$inputfieldFile->renderReady();
		}
		
		return parent::renderReady($parent, $renderValueMode);
	}

	/**
	 * Render markup for a file item in admin
	 *
	 * @param Pagefile $pagefile
	 * @param string $id
	 * @param int $n
	 * @return string
	 */
	protected function ___renderItem($pagefile, $id, $n) {
		$displayName = $this->getDisplayBasename($pagefile);
		$deleteLabel = $this->labels['delete'];
		
		// Choose URL based on storage location
		// Use FAST URL generation without API calls
		if($this->localStorage) {
			$url = $pagefile->url;
		} else {
			// Use fast method - no API calls!
			$url = $this->generateB2UrlFast($pagefile);
		}
		
		$out = "<p class='InputfieldFileInfo InputfieldItemHeader ui-state-default ui-widget-header'>";
		
		// ADD DRAG HANDLE for sorting (visible even in collapsed view)
		if(!$this->renderValueMode && count($this->value) > 1) {
			$out .= "<i class='fa fa-fw fa-arrows InputfieldFileDrag' title='Drag to sort'></i> ";
		}
		
		$out .= wireIconMarkupFile($pagefile->basename, "fa-fw HideIfEmpty") .
			"<a class='InputfieldFileName' title='$pagefile->basename' target='_blank' href='{$url}'>$displayName</a> " .
			"<span class='InputfieldFileStats'>" . str_replace(' ', '&nbsp;', wireBytesStr($pagefile->fSize)) . "</span> ";

		if(!$this->renderValueMode) {
			$out .= "<label class='InputfieldFileDelete'>" .
				"<input type='checkbox' name='delete_$id' value='1' title='$deleteLabel' />" .
				"<i class='fa fa-fw fa-trash'></i></label>";
		}

		$out .= "</p>" .
			"<div class='InputfieldFileData description ui-widget-content'>" .
			$this->renderItemDescriptionField($pagefile, $id, $n);

		if(!$this->renderValueMode) {
			$out .= "<input class='InputfieldFileSort' type='text' name='sort_$id' value='$n' />";
		}

		$out .= "</div>";

		return $out;
	}

	/**
	 * Called when a file is added
	 *
	 * @param Pagefile $pagefile
	 * @throws WireException
	 */
	protected function ___fileAdded(Pagefile $pagefile) {
		if($this->noUpload) return;

		// Debug log

		// Validate file
		$isValid = $this->wire('sanitizer')->validateFile($pagefile->filename(), array(
			'pagefile' => $pagefile
		));

		if($isValid === false) {
			$errors = $this->wire('sanitizer')->errors('clear array');
			$errorMsg = $this->_('File failed validation') . (count($errors) ? ": " . implode(', ', $errors) : "");
			throw new WireException($errorMsg);
		}

		$message = $this->_('Added file:') . " {$pagefile->basename}";

		// Get page ID (works in both AJAX and non-AJAX modes)
		$pageId = $this->input->get->id ? (int)$this->input->get->id : ($pagefile->page ? (int)$pagefile->page->id : 0);

		// IMPORTANT: Set file size BEFORE uploading to B2 and deleting local file
		$pagefile->fSize = @filesize($pagefile->filename);

		// Upload to B2 if not using local storage (ALWAYS, not just for AJAX)
		if(!$this->localStorage) {
			try {
				$result = $this->uploadFileToB2($pagefile, $pageId);
			} catch(\Exception $e) {
				$errorMsg = "B2 Upload Error: " . $e->getMessage();
				$this->error($errorMsg);
				throw $e;
			}
		} else {
		}

		if($this->isAjax && !$this->noAjax) {
			$n = count($this->value);
			if($n) $n--;
			$this->currentItem = $pagefile;
			$markup = $this->fileAddedGetMarkup($pagefile, $n);
			// Use B2 URL for AJAX response (local file will be deleted after page save)
			$ajaxUrl = $this->localStorage ? $pagefile->url : $this->generateB2UrlFast($pagefile);
			$this->ajaxResponse(false, $message, $ajaxUrl, $pagefile->fSize, $markup);
		} else {
			$this->message($message);
		}
	}

	/**
	 * Process input for adding a file
	 *
	 * @param string $filename
	 * @throws WireException
	 */
	protected function ___processInputAddFile($filename) {
		
		$total = count($this->value);
		
		$metadata = array();
		$rm = null;

		if($this->maxFiles > 1 && $total >= $this->maxFiles) {
			return;
		}

		// Handle file replacement for single file fields
		if($this->maxFiles == 1 && $total) {
			$pagefile = $this->value->first();
			$metadata = $this->extractMetadata($pagefile, $metadata);
			$rm = true;
			if($filename == $pagefile->basename) {
				if($this->overwrite) $rm = false;
			}
			if($rm) {
				if($this->overwrite) $this->processInputDeleteFile($pagefile);
				$this->singleFileReplacement = true;
			}
		}

		if($this->overwrite) {
			$pagefile = $this->value->get($filename);
			clearstatcache();
			if($pagefile) {
				if($pagefile instanceof Pageimage) $pagefile->removeVariations();
				$metadata = $this->extractMetadata($pagefile, $metadata);
			} else {
				$ul = $this->getWireUpload();
				$err = false;
				foreach($ul->getOverwrittenFiles() as $bakFile => $newFile) {
					if(basename($newFile) != $filename) continue;
					unlink($newFile);
					rename($bakFile, $newFile);
					$ul->error(sprintf($this->_('Refused file %s because it is already on the file system and owned by a different field.'), $filename));
					$err = true;
				}
				if($err) {
					return;
				}
			}
		}

		$this->value->add($filename);
		$item = $this->value->last();

		try {
			foreach($metadata as $key => $val) {
				if($val) $item->$key = $val;
			}
			if($this->isAjax && !$this->overwrite && $this->localStorage) {
				$item->isTemp(true);
			}
			$this->fileAdded($item);
		} catch(\Exception $e) {
			$item->unlink();
			$this->value->remove($item);
			throw new WireException($e->getMessage());
		}
		
	}

	/**
	 * Process input for deleting a file
	 *
	 * @param Pagefile $pagefile
	 */
	protected function ___processInputDeleteFile(Pagefile $pagefile) {
		$this->message($this->_("Deleted file:") . " $pagefile");
		$this->value->delete($pagefile);
		$this->trackChange('value');
		
		if(!$this->localStorage) {
			try {
				$this->deleteFileFromB2($pagefile, (int)$this->input->get->id);
			} catch(\Exception $e) {
				$this->error("B2 Delete Error: " . $e->getMessage());
			}
		}
	}

	/**
	 * Process all input for this field
	 *
	 * @param WireInputData $input
	 * @return $this
	 */
	public function ___processInput(WireInputData $input) {
		
		if(is_null($this->value)) {
			$this->value = $this->wire(new Pagefiles($this->wire('page')));
		}
		
		if(!$this->destinationPath) {
			$this->destinationPath = $this->value->path();
		}
		
		if(!$this->destinationPath || !is_dir($this->destinationPath)) {
			return $this->error($this->_("destinationPath is empty or does not exist"));
		}
		
		if(!is_writable($this->destinationPath)) {
			return $this->error($this->_("destinationPath is not writable"));
		}

		$changed = false;
		$total = count($this->value);

		if(!$this->noUpload) {
			if($this->maxFiles <= 1 || $total < $this->maxFiles) {
				$ul = $this->getWireUpload();
				$ul->setName($this->attr('name'));
				$ul->setDestinationPath($this->destinationPath);
				$ul->setOverwrite($this->overwrite);
				$ul->setAllowAjax($this->noAjax ? false : true);
				
				if($this->maxFilesize) {
					$ul->setMaxFileSize($this->maxFilesize);
				}

				if($this->maxFiles == 1) {
					$ul->setMaxFiles(1);
				} else if($this->maxFiles) {
					$maxFiles = $this->maxFiles - $total;
					$ul->setMaxFiles($maxFiles);
				} else if($this->unzip) {
					$ul->setExtractArchives(true);
				}

				$extensions = explode(' ', trim($this->extensions));
				$ul->setValidExtensions($extensions);
				
				
				$uploadedFiles = $ul->execute();
				$uploadCount = is_array($uploadedFiles) ? count($uploadedFiles) : 0;
				
				if($uploadCount == 0) {
					$errors = $ul->getErrors();
					if(count($errors)) {
					} else {
					}
				}
				
				foreach($uploadedFiles as $filename) {
					$this->processInputAddFile($filename);
					$changed = true;
				}

				if($this->isAjax && !$this->noAjax) {
					$errors = $ul->getErrors();
					foreach($errors as $error) {
						$this->ajaxResponse(true, $error);
					}
				}
			} else if($this->maxFiles) {
				$this->ajaxResponse(true, $this->_("Max file upload limit reached"));
			}
		} else {
		}

		$n = 0;
		foreach($this->value as $pagefile) {
			if($this->processInputFile($input, $pagefile, $n)) {
				$changed = true;
			}
			// NOTE: Local files are now deleted by hookPageSaved() after page is saved
			// This ensures file size is properly set before deletion
			$n++;
		}
		
		if($changed) {
			$this->value->sort('sort');
			$this->trackChange('value');
		}
		
		if(count($this->ajaxResponses) && $this->isAjax) {
			echo json_encode($this->ajaxResponses);
		}

		return $this;
	}

	/**
	 * Build the public B2 URL for a file.
	 *
	 * Supports custom domain and the standard friendly B2 URL format.
	 * Calls authenticateB2() if the region is not yet known — the result
	 * is cached in WireCache for 23 hours so subsequent calls are free.
	 *
	 * @param Pagefile $pagefile
	 * @return string
	 */
	protected function generateB2UrlFast($pagefile) {
		$ssl      = $this->useSSL ? 'https' : 'http';
		$fileName = $pagefile->page->id . '/' . $pagefile->name;

		// Custom domain takes priority
		if($this->useCustomDomain && !empty($this->customDomain)) {
			return "{$ssl}://{$this->customDomain}/{$fileName}";
		}

		// Ensure we know the region (uses cached auth — almost always free)
		if(!$this->apiUrl) {
			try {
				$this->authenticateB2();
			} catch(\Exception $e) {
				// Fallback: best-effort URL with default region
				return "{$ssl}://f005.backblazeb2.com/file/{$this->bucketName}/{$fileName}";
			}
		}

		$region = '005'; // fallback
		if(preg_match('/api(\d{3})\.backblazeb2\.com/', $this->apiUrl, $m)) {
			$region = $m[1];
		}

		return "{$ssl}://f{$region}.backblazeb2.com/file/{$this->bucketName}/{$fileName}";
	}

	/**
	 * Hook: return B2 URL for $pagefile->b2url property access.
	 *
	 * @param HookEvent $event
	 */
	protected function hookB2Url($event) {
		$pagefile = $event->object;

		if($this->localStorage) {
			$event->return = $pagefile->url;
			return;
		}

		$event->return = $this->generateB2UrlFast($pagefile);
	}

	/**
	 * Authenticate with Backblaze B2 API.
	 *
	 * Uses HTTP Basic Auth (keyId:applicationKey, base64-encoded).
	 * The result is cached in WireCache for 23 hours so only one real
	 * API call is made per day across all PHP requests.
	 *
	 * @throws WireException on authentication failure
	 */
	protected function authenticateB2() {
		// Already authenticated in this request
		if($this->authToken && $this->apiUrl) return;

		// Validate credentials exist
		if(empty($this->b2KeyId) || empty($this->b2ApplicationKey)) {
			throw new WireException("B2 credentials not configured");
		}

		// Try to restore from WireCache — tokens are valid 24 h, we cache for 23 h
		$cache    = $this->wire('cache');
		$cacheKey = 'InputfieldFileB2_auth_' . md5($this->b2KeyId);
		if($cache) {
			$cached = $cache->get($cacheKey);
			if($cached && isset($cached['authorizationToken'])) {
				$this->authToken   = $cached['authorizationToken'];
				$this->apiUrl      = $cached['apiUrl'];
				$this->downloadUrl = $cached['downloadUrl'];
				$this->b2AccountId = $cached['accountId'];
				return;
			}
		}

		// Real API call
		$authString = base64_encode("{$this->b2KeyId}:{$this->b2ApplicationKey}");

		$ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Basic {$authString}"));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$response  = curl_exec($ch);
		$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if($curlError) {
			throw new WireException("B2 Authentication cURL error: " . $curlError);
		}

		if($httpCode !== 200) {
			$errorMsg  = "B2 Authentication failed (HTTP {$httpCode})";
			$errorData = json_decode($response, true);
			if(isset($errorData['message'])) $errorMsg .= ": " . $errorData['message'];
			throw new WireException($errorMsg);
		}

		$data = json_decode($response, true);

		if(!isset($data['authorizationToken']) || !isset($data['apiUrl'])) {
			throw new WireException("B2 Authentication response missing required fields");
		}

		$this->authToken   = $data['authorizationToken'];
		$this->apiUrl      = $data['apiUrl'];
		$this->downloadUrl = $data['downloadUrl'];
		$this->b2AccountId = $data['accountId'];

		// Persist to WireCache for 23 hours
		if($cache) {
			$cache->save($cacheKey, array(
				'authorizationToken' => $this->authToken,
				'apiUrl'             => $this->apiUrl,
				'downloadUrl'        => $this->downloadUrl,
				'accountId'          => $this->b2AccountId,
			), 23 * 3600);
		}
	}

	/**
	 * Get upload URL and authorization token for B2
	 * 
	 * B2 requires getting a unique upload URL for each file upload
	 * The upload URL is valid for 24 hours or until used
	 *
	 * @return array Upload URL and authorization token
	 * @throws WireException on failure
	 */
	protected function getUploadUrl() {
		$this->authenticateB2();

		if(empty($this->bucketId)) {
			throw new WireException("Bucket ID not configured");
		}

		$ch = curl_init("{$this->apiUrl}/b2api/v2/b2_get_upload_url");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Authorization: {$this->authToken}",
			"Content-Type: application/json"
		));
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'bucketId' => $this->bucketId
		)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);

		if($curlError) {
			throw new WireException("Get upload URL cURL error: " . $curlError);
		}

		if($httpCode !== 200) {
			throw new WireException("Failed to get upload URL (HTTP {$httpCode}): {$response}");
		}

		$data = json_decode($response, true);
		
		if(!isset($data['uploadUrl']) || !isset($data['authorizationToken'])) {
			throw new WireException("Upload URL response missing required fields");
		}

		return $data;
	}

	/**
	 * Upload file to Backblaze B2
	 * 
	 * Automatically chooses between:
	 * - Standard upload for files < 50MB
	 * - Large file (chunked) upload for files >= 50MB
	 *
	 * @param Pagefile $pagefile File to upload
	 * @param int $pageID Page ID for organizing files
	 * @return array B2 file info
	 * @throws WireException on upload failure
	 */
	protected function uploadFileToB2($pagefile, $pageID) {
		$fileSize = filesize($pagefile->filename);
		$fileSizeMB = round($fileSize / 1024 / 1024, 2);
		
		// Use chunked upload for files >= 50MB
		if($fileSize >= 50 * 1024 * 1024) {
			$this->message("Starting chunked upload for {$fileSizeMB}MB file", Notice::log);
			return $this->uploadLargeFileToB2($pagefile, $pageID);
		}
		
		// Standard upload for smaller files
		$this->message("Starting standard upload for {$fileSizeMB}MB file", Notice::log);
		return $this->uploadStandardFileToB2($pagefile, $pageID);
	}
	
	/**
	 * Standard B2 upload for files < 50 MB.
	 *
	 * Uses streaming via CURLOPT_INFILE so the file is never loaded
	 * into PHP memory. SHA1 is computed separately with sha1_file().
	 */
	protected function uploadStandardFileToB2($pagefile, $pageID) {
		$uploadData  = $this->getUploadUrl();
		$fileSize    = filesize($pagefile->filename);
		$fileName    = "{$pageID}/{$pagefile->name}";
		// sha1_file() reads sequentially — no full-file memory load
		$sha1        = sha1_file($pagefile->filename);
		$contentType = mime_content_type($pagefile->filename) ?: 'application/octet-stream';

		$headers = array(
			"Authorization: {$uploadData['authorizationToken']}",
			"X-Bz-File-Name: " . rawurlencode($fileName),
			"Content-Type: {$contentType}",
			"Content-Length: {$fileSize}",
			"X-Bz-Content-Sha1: {$sha1}",
			"X-Bz-Info-src_last_modified_millis: " . (filemtime($pagefile->filename) * 1000),
		);

		if(!empty($this->cacheControl) && $this->cacheControl > 0) {
			$headers[] = "X-Bz-Info-b2-cache-control: max-age={$this->cacheControl}";
		}

		$fp = fopen($pagefile->filename, 'rb');
		if(!$fp) throw new WireException("Failed to open file for reading: {$pagefile->filename}");

		$response  = null;
		$httpCode  = 0;
		$curlError = '';

		try {
			$ch = curl_init($uploadData['uploadUrl']);
			// Use PUT + CURLOPT_INFILE for streaming; override method to POST
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($ch, CURLOPT_PUT, true);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_INFILE, $fp);
			curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 300);

			$response  = curl_exec($ch);
			$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curlError = curl_error($ch);
			curl_close($ch);
		} finally {
			fclose($fp);
		}

		if($curlError) throw new WireException("B2 Upload cURL error: " . $curlError);

		if($httpCode !== 200) {
			$errorMsg  = "B2 Upload failed (HTTP {$httpCode})";
			$errorData = json_decode($response, true);
			if(isset($errorData['message'])) {
				$errorMsg .= ": " . $errorData['message'];
			} else {
				$errorMsg .= ": " . $response;
			}
			throw new WireException($errorMsg);
		}

		return json_decode($response, true);
	}
	
	/**
	 * Large file (chunked) upload for files >= 50MB
	 * Uses B2 Large File API with 10MB chunks
	 */
	protected function uploadLargeFileToB2($pagefile, $pageID) {
		$this->authenticateB2();
		
		$fileName = "{$pageID}/{$pagefile->name}";
		$fileSize = filesize($pagefile->filename);
		$contentType = mime_content_type($pagefile->filename);
		if(!$contentType) $contentType = 'application/octet-stream';
		
		// 1. Start large file upload
		$ch = curl_init("{$this->apiUrl}/b2api/v2/b2_start_large_file");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Authorization: {$this->authToken}",
			"Content-Type: application/json"
		));
		
		$fileInfo = array();
		if(!empty($this->cacheControl) && $this->cacheControl > 0) {
			$fileInfo['b2-cache-control'] = "max-age={$this->cacheControl}";
		}
		
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'bucketId' => $this->bucketId,
			'fileName' => $fileName,
			'contentType' => $contentType,
			'fileInfo' => $fileInfo
		)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		if($httpCode !== 200) {
			throw new WireException("Failed to start large file upload (HTTP {$httpCode}): {$response}");
		}
		
		$startData = json_decode($response, true);
		$fileId = $startData['fileId'];
		
		// 2. Upload chunks (10MB each)
		$chunkSize = 10 * 1024 * 1024; // 10MB
		$partNumber = 1;
		$sha1Array = array();
		$totalChunks = ceil($fileSize / $chunkSize);
		
		$this->message("Uploading {$totalChunks} chunks of 10MB each", Notice::log);
		
		$fp = fopen($pagefile->filename, 'rb');
		if(!$fp) throw new WireException("Failed to open file for reading");

		try {
			while(!feof($fp)) {
				// Get upload part URL
				$ch = curl_init("{$this->apiUrl}/b2api/v2/b2_get_upload_part_url");
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					"Authorization: {$this->authToken}",
					"Content-Type: application/json"
				));
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('fileId' => $fileId)));
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_TIMEOUT, 30);

				$response = curl_exec($ch);
				$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				curl_close($ch);

				if($httpCode !== 200) {
					throw new WireException("Failed to get upload part URL (HTTP {$httpCode})");
				}

				$partData = json_decode($response, true);

				// Read chunk
				$chunk = fread($fp, $chunkSize);
				if($chunk === false) throw new WireException("Failed to read file chunk");
				if(strlen($chunk) === 0) break; // End of file

				$chunkSha1  = sha1($chunk);
				$sha1Array[] = $chunkSha1;

				// Upload chunk
				$ch = curl_init($partData['uploadUrl']);
				curl_setopt($ch, CURLOPT_HTTPHEADER, array(
					"Authorization: {$partData['authorizationToken']}",
					"X-Bz-Part-Number: {$partNumber}",
					"Content-Length: " . strlen($chunk),
					"X-Bz-Content-Sha1: {$chunkSha1}"
				));
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $chunk);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_TIMEOUT, 300);

				$response  = curl_exec($ch);
				$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				$curlError = curl_error($ch);
				curl_close($ch);

				if($curlError) throw new WireException("Chunk upload cURL error: " . $curlError);

				if($httpCode !== 200) {
					throw new WireException("Failed to upload chunk {$partNumber} (HTTP {$httpCode}): {$response}");
				}

				$this->message("Uploaded chunk {$partNumber}/{$totalChunks}", Notice::log);
				$partNumber++;
			}

			fclose($fp);
			$fp = null;

		} catch(\Exception $e) {
			// Close file handle
			if($fp) { fclose($fp); $fp = null; }
			// Cancel the incomplete large file to avoid orphaned uploads on B2
			$this->cancelLargeFileUpload($fileId);
			throw $e;
		}

		// 3. Finish large file upload
		$this->message("Finalizing upload...", Notice::log);

		$ch = curl_init("{$this->apiUrl}/b2api/v2/b2_finish_large_file");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Authorization: {$this->authToken}",
			"Content-Type: application/json"
		));
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'fileId'        => $fileId,
			'partSha1Array' => $sha1Array
		)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if($httpCode !== 200) {
			$this->cancelLargeFileUpload($fileId);
			throw new WireException("Failed to finish large file upload (HTTP {$httpCode}): {$response}");
		}

		$this->message("Successfully uploaded " . count($sha1Array) . " chunks", Notice::log);

		return json_decode($response, true);
	}

	/**
	 * Cancel an incomplete B2 large file upload (best-effort, never throws).
	 *
	 * @param string $fileId
	 */
	protected function cancelLargeFileUpload($fileId) {
		try {
			$ch = curl_init("{$this->apiUrl}/b2api/v2/b2_cancel_large_file");
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				"Authorization: {$this->authToken}",
				"Content-Type: application/json"
			));
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('fileId' => $fileId)));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
			curl_exec($ch);
			curl_close($ch);
			$this->message("Cancelled incomplete large file upload (fileId: {$fileId})", Notice::log);
		} catch(\Exception $e) {
			// Ignore — best effort only
		}
	}

	/**
	 * Delete file from Backblaze B2
	 * 
	 * Process:
	 * 1. List files to get file ID
	 * 2. Delete file by ID and name
	 *
	 * @param Pagefile $pagefile File to delete
	 * @param int $pageID Page ID
	 * @throws WireException on delete failure
	 */
	protected function deleteFileFromB2($pagefile, $pageID) {
		$this->authenticateB2();
		
		if(empty($this->bucketId)) {
			throw new WireException("Bucket ID not configured");
		}
		
		// Build file name
		$fileName = "{$pageID}/{$pagefile->name}";
		
		// First, get file info to obtain fileId
		$ch = curl_init("{$this->apiUrl}/b2api/v2/b2_list_file_names");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Authorization: {$this->authToken}",
			"Content-Type: application/json"
		));
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'bucketId' => $this->bucketId,
			'startFileName' => $fileName,
			'maxFileCount' => 1,
			'prefix' => $fileName
		)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if($httpCode !== 200) {
			throw new WireException("Failed to list file for deletion (HTTP {$httpCode}): {$response}");
		}

		$data = json_decode($response, true);
		
		// Check if file was found
		if(!isset($data['files'][0]['fileId']) || $data['files'][0]['fileName'] !== $fileName) {
			// File not found on B2, silently return
			return;
		}

		$fileId = $data['files'][0]['fileId'];
		
		// Delete the file
		$ch = curl_init("{$this->apiUrl}/b2api/v2/b2_delete_file_version");
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Authorization: {$this->authToken}",
			"Content-Type: application/json"
		));
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
			'fileId' => $fileId,
			'fileName' => $fileName
		)));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		
		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if($httpCode !== 200) {
			throw new WireException("B2 Delete failed (HTTP {$httpCode}): {$response}");
		}
	}
	
	
	/**
	 * Hook: delete local copies of files after the page is saved.
	 *
	 * Files are uploaded to B2 during ___fileAdded(), but the local copy
	 * is only safe to remove once the page (and thus DB record) is saved.
	 *
	 * @param HookEvent $event
	 */
	public function hookPageSaved($event) {
		// Nothing to do when using local storage
		if($this->localStorage) return;

		$page = $event->arguments(0);

		// Skip pages that have no fields at all
		if(!$page->fields || !count($page->fields)) return;

		foreach($page->fields as $field) {
			if(!($field->type instanceof FieldtypeFileB2)) continue;

			$pagefiles = $page->get($field->name);
			if(!$pagefiles || !count($pagefiles)) continue;

			foreach($pagefiles as $pagefile) {
				if(!file_exists($pagefile->filename)) continue;
				if(!unlink($pagefile->filename)) {
					$this->error(
						"Could not delete local file after B2 upload: {$pagefile->filename}",
						Notice::log
					);
				}
			}
		}
	}
}
