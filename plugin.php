<?php

class pluginWpImporter extends Plugin {

    private $loadOnController = array(
		'configure-plugin'
	    );

    public function init()
	{
	    // Fields and default values for the database of this plugin
		$this->dbFields = array(
			'lastWebsite'=>'',
			'importMarkdown'=> false,
			'importImages'=>true
		);
	}

    //add styles and scripts

    public function adminHead()
	{

        if (in_array($GLOBALS['ADMIN_CONTROLLER'], $this->loadOnController)) {
		    global $url;
            $slug = basename( $url->uri());
            if($slug!=get_class($this)) return false; //load only on current plugin

            $html = '<link rel="stylesheet" href="'.$this->htmlPath().'css/app.min.css">';
		    return $html;
		}
        return false;
	}

    public function adminBodyEnd()
	{

        if (in_array($GLOBALS['ADMIN_CONTROLLER'], $this->loadOnController)) {
		    global $url;
            $slug = basename( $url->uri());
            if($slug!=get_class($this)) return false; //load only on current plugin        
		    return '<script src="'.$this->htmlPath().'js/app.comp.js"></script>';
		}
        return false;

	}


	public function form()
	{
		global $language;
        global $url;
?>
<div>
	<a class="pl-logo" href="https://blthemes.pp.ua" target="_blank">BlThemes</a>
</div>
<div id="appWp"></div>

<?php
        //init variables
        $scr =  '<script>'. PHP_EOL;
        $scr .= '   var postWpUri        = "'. $url->uri().'";' . PHP_EOL;
        $scr .= '   var lastWebsite      = "'. $this->getValue('lastWebsite') .'";' . PHP_EOL;
        $scr .= '   var importImages     = "'. $this->getValue('importImages') .'";' . PHP_EOL;
        $scr .= '   var importMarkdown   = "'. $this->getValue('importMarkdown') .'";'. PHP_EOL;
        $scr .= '</script>';
        echo $scr;
?>

<?php
	}

    private $markdown = true;
    private $importImages = true;

	public function post()
	{
        set_time_limit(180); //180 seconds max execution time
		if(isset( $_POST['raboti']) ){
            if ( isset($_POST['markdown']) )   $this->markdown     = (bool) $_POST['markdown'];
            if ( isset($_POST['impImages']) )  $this->importImages = (bool) $_POST['impImages'];
            if ( isset( $_POST['wpPost']) ) {
                $wpPost    = json_decode($_POST['wpPost'], true);
                $slug = $this->createPost( $wpPost );
                header('Content-Type: application/json');
                exit (json_encode(array(
                    'status'=>'success',
                    'slug'=>$slug
                )));
            }
            elseif(isset( $_POST['wpPosts'])){
                $wpPosts    = json_decode($_POST['wpPosts'], true);

                $slugs = [];
                foreach ($wpPosts as $wpPost){
                    $slug = $this->createPost( $wpPost );
                    $slugs[] = $slug;
                }

                header('Content-Type: application/json');
                exit (json_encode(array(
                    'status'=>'success',
                    'slugs'=>$slugs
                )));
            }
            else return false;
        }
        else{
            return parent::post();
        }
	}

    private function createPost($wpPost){
        global $pages;
        global $categories;
        try {
            $blPost['title'] =	  Sanitize::html($wpPost['title']);
			$blPost['uuid'] = $pages -> generateUUID();
            if( $this->importImages ){
                $blPost['coverImage'] = $this -> copyImage($wpPost['coverImage'], $blPost['uuid']) ;
            }
            else{
                $blPost['coverImage'] = $wpPost['coverImage'];
            }

            $blPost['content'] = $this->processContent($wpPost['content'], $blPost['uuid']);


			// $blPost['status'] =	'published';
            $date = new DateTime($wpPost['date']);
            $blPost['date'] =	$date->format('Y-m-d H:i:s');

            $blPost['type'] = 'published';
            //$blPost['template'] = 'WP Import';
            $blPost['tags'] =	 trim($wpPost['tags'],',');
            $blPost['slug'] =	$wpPost['slug'] ? $wpPost['slug'] : Text::cleanUrl($wpPost['title'] );		

            $blPost['category'] = $wpPost['category'] ;
            $catSlug = Text::cleanUrl($blPost['category'] );
            if(! $categories->getName($catSlug)){ //create category if not exist
                $args['name'] = $blPost['category'];
				createCategory($args );
            }
            $blPost['category'] = $catSlug;


            if(!empty($wpPost['excerpt'])){
                $blPost['description'] = Sanitize::html( Text::truncate( Text::removeHTMLTags( $wpPost['excerpt']),250));
            }
            else{
                $blPost['description'] = Sanitize::html( Text::truncate( Text::removeHTMLTags( $wpPost['content']),250));
            }


            createPage($blPost);
            return $wpPost['slug'];
        }
        catch (Exception $e) {
            header('Content-Type: application/json');
            exit (json_encode(array(
	            'status'=>'error',
                'message'=> $e->getMessage()
            )));
        }
    }

	private function processContent($content, $uuid)
	{

        if(!$content) return '';
        $doc = new DOMDocument();
        $doc->loadHTML('<?xml encoding="UTF-8"><body>' . $content .' </body>',  LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR );
        $doc->encoding = 'UTF-8';
        $content = $doc->saveHTML($doc->documentElement);

        $unwanted = array('<html>','</html>','<body>','</body>', '<?xml encoding="UTF-8">', '&#xD;');
		$content = str_replace( $unwanted, '', $content); //clean


        if($this->importImages){
            $images = $doc->getElementsByTagName('img');
            foreach ($images as $image) {
                $elHtml = $doc->saveHTML($image);

                $src = $image->getAttribute('src');
                $alt = $image->getAttribute('alt');

                $filename = strtok( basename($src),'?');
                $filename = $this->create_bl_image( $src , $filename, $uuid, true);
				$imagesURL = (IMAGE_RELATIVE_TO_ABSOLUTE? '' : DOMAIN_UPLOADS_PAGES.$uuid.'/');
                $bluditImg = '<img src="'.$imagesURL.$filename.'" alt="'.$alt.'">';
				$content = str_replace($elHtml, $bluditImg, $content);
            }
        }
        if($this->markdown){

            require_once('html2md.php');
            $content = html2markdown($content);
        }

		return $content;
	}

    private function copyImage($imagesrc, $uuid){
        $filename = strtok( basename($imagesrc),'?');
        return $this->create_bl_image( $imagesrc , $filename, $uuid, true);
    }


	private function create_bl_image($img_link, $file_name, $uuid=null, $thumb = false)
	{
		if ($uuid && IMAGE_RESTRICT) {
			$uploadDirectory = PATH_UPLOADS_PAGES.$uuid.DS;
			$thumbnailDirectory = $uploadDirectory.'thumbnails'.DS;
		} else {
			$uploadDirectory = PATH_UPLOADS;
			$thumbnailDirectory = PATH_UPLOADS_THUMBNAILS;
		}
		// Create directory for images
		if (!is_dir($uploadDirectory)){
			Filesystem::mkdir($uploadDirectory, true);
		}

		// Create directory for thumbnails
		if (!is_dir($thumbnailDirectory)){
			Filesystem::mkdir($thumbnailDirectory, true);
		}


		$fileExtension = pathinfo(  $file_name, PATHINFO_EXTENSION);
        if ($fileExtension == 'svg'){
            if(@copy($img_link,  $uploadDirectory . $file_name)){
                return $img_link;
            }
            @copy($img_link,  $thumbnailDirectory . $file_name);
        	return $file_name;
        }
        require_once('resizeImage.php');
		global $site;
        if (is_file(PATH_UPLOADS . $file_name)) {

			return $file_name; //file exist
		}


		if (!@copy($img_link, $uploadDirectory . $file_name)) {
			return $img_link;
		}
        if($thumb){
            $image = new ResizeImage($uploadDirectory . $file_name, $thumbnailDirectory . $file_name);
            $tmb   = $image->resize(300, 300);
            unset($image);

        }
		return $file_name;
	}

}
