<?php namespace ProcessWire;

class InputfieldFileB2Config extends ModuleConfig {

    public function __construct() {
        $this->add(array(
            // Text field: Key ID
            array(
                'name'  => 'b2KeyId',
                'type'  => 'text',
                'label' => $this->_('Backblaze Key ID'),
                'description' => $this->_('Application Key ID used for authentication'),
                'notes' => $this->_("[Create App Keys](https://secure.backblaze.com/app_keys.htm)"),
                'required' => true,
				'columnWidth' => 50,
                'value' => $this->_(''),
            ),
            // Text field: Application Key
            array(
                'name'  => 'b2ApplicationKey',
                'type'  => 'text',
                'label' => $this->_('Backblaze Application Key'),
                'description' => $this->_('Application Key used for authentication'),
                'notes' => $this->_("Keep this secret! [More Info](https://www.backblaze.com/b2/docs/application_keys.html)"),
                'required' => true,
				'columnWidth' => 50,
                'value' => $this->_(''),
            ),
            // Text field: Bucket Name
            array(
                'name'  => 'bucketName',
                'type'  => 'text',
                'label' => $this->_('Bucket Name'),
                'description' => $this->_("Set bucket name. Bucket must exist beforehand."),
                'notes' => $this->_("[Manage Buckets](https://secure.backblaze.com/b2_buckets.htm)"),
                'required' => true,
				'columnWidth' => 50,
                'value' => $this->_(''),
            ),
            // Text field: Bucket ID
            array(
                'name'  => 'bucketId',
                'type'  => 'text',
                'label' => $this->_('Bucket ID'),
                'description' => $this->_("Bucket ID from Backblaze B2"),
                'notes' => $this->_("Find this in your bucket settings"),
                'required' => true,
				'columnWidth' => 50,
                'value' => $this->_(''),
            ),
			// Select field: Bucket Type
			array(
				'name'  => 'bucketType',
				'type'  => 'select',
				'label' => $this->_('Bucket Type'),
				'description' => $this->_('Public or Private bucket'),
				'notes' => $this->_("Public buckets allow direct URL access. Private requires signed URLs."),
				'required' => true,
				'options' => array(
					'allPublic'  => 'Public',
					'allPrivate' => 'Private',
				),
				'columnWidth' => 50,
				'value' => $this->_('allPublic'),
			),

			// Checkbox field: useSSL
			array(
				'name'  => 'useSSL',
				'type'  => 'checkbox',
				'label' => $this->_('Use SSL'),
				'description' => $this->_('If checked it will use https for the files url.'),
				'columnWidth' => 50,
				'value' => $this->_('1'),
			),

			// Checkbox field: useCustomDomain
			array(
				'name'  => 'useCustomDomain',
				'type'  => 'checkbox',
				'label' => $this->_('Use Custom Domain'),
				'description' => $this->_('Use custom domain to serve files'),
				'notes' => $this->_(
					'If checked will use your custom domain to serve files. You need to set up CNAME pointing to Backblaze B2.
					[Info!](https://help.backblaze.com/hc/en-us/articles/217666928-Using-a-Custom-Domain-with-Backblaze-B2)'
				),
				'columnWidth' => 50,
				'value' => $this->_('0'),
			),

			// Text field: Custom Domain
			array(
				'name'  => 'customDomain',
				'type'  => 'text',
				'label' => $this->_('Custom Domain'),
				'description' => $this->_('Your custom domain for serving files (e.g., cdn.example.com)'),
				'notes' => $this->_('Only used if "Use Custom Domain" is checked'),
				'columnWidth' => 100,
				'value' => $this->_(''),
				'showIf' => 'useCustomDomain=1'
			),

			// Checkbox field: localStorage
			array(
				'name'  => 'localStorage',
				'type'  => 'checkbox',
				'label' => $this->_('Store files locally'),
				'description' => $this->_('If checked, files will be stored locally instead of Backblaze B2.'),
				'notes' => $this->_(
					'This option only changes the url of the files from B2 to local and disables local file deletion.
					For files already on B2 you\'d have to transfer them yourself.'
				),
				'columnWidth' => 50,
				'value' => $this->_('1'),
			),
            
            // Cache Control
            array(
                'name'  => 'cacheControl',
                'type'  => 'integer',
                'notes' => $this->_('Ex: 3600 = 1 hour; 86400 = 24 hours; 604800 = 7 days; 2592000 = 30 days'),
                'label' => $this->_('Cache-Control max-age (seconds)'),
                'description' => $this->_('Set Cache-Control header for uploaded files. Leave blank for default.'),
                'columnWidth' => 100,
                'value' => 86400
            )
        ));
    }
}
