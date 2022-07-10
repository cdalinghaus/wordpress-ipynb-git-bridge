<?php
/**
 * Plugin Name: Jupyter notebooks from github
 * Plugin URI: https://github.com/cdalinghaus/wordpress-ipynb-git-bridge
 * Description: Render Jupyter notebooks as blog posts
 * Version: 0.1.0
 * Author: cdalinghaus
 * Author URI: https://github.com/cdalinghaus
 */

// Cache for 30 days
$CACHE_DURATION_SECONDS = 60*60*24*30;
// Where to store the images and cached notebook files
$STORAGE_LOCATION = plugin_dir_path( __FILE__ ) . "../../uploads/ipynb-media/";

function base64_img_to_webp( $base64_string, $file_extension ) {
    /**
     * Convert base64 file to static .webp. Currently only implemented with .png,
     * should^TM however work with .jpg as well
     *
     * @param $base64_string Base64 encoded image
     * @param $file_extension File extension of the image
     * 
     * @return fill http path to the .webp image 
     */

    global $STORAGE_LOCATION;

    // Create folder if not exists
    // base64_img_to_webp is currently only called after the folder was already checked for
    // (or created) so this is currently redundant
    if (!file_exists($storage_location)) {
         mkdir($STORAGE_LOCATION, 0777, true);
    }

    $filename = md5($base64_string) . "." . $file_extension;

    $md5filename = md5($base64_string);

    $file = $STORAGE_LOCATION . $filename;

    $ifp = fopen( $file, "wa+" ); 
    fwrite( $ifp, base64_decode( $base64_string) ); 
    fclose( $ifp ); 

    $img = imagecreatefrompng($file);
    imagepalettetotruecolor($img);
    imagealphablending($img, true);
    imagesavealpha($img, true);
    imagewebp($img, $dir . $STORAGE_LOCATION . $md5filename  . ".webp", 100);
    imagedestroy($img);
    unlink($file);

    return get_site_url() . "/wp-content/uploads/ipynb-media/" . md5($base64_string) . ".webp";
}


function optimize_json($jsonstring) {
    /**
     * "Optimizes" json i.e. turns base64 images into static .webp files and the metadata cell if it can be found 
     *
     * @param $jsonstring String of notebook json
     * 
     * @return "Optimized" json as per definition above
     */ 
    $json = json_decode($jsonstring, true);
    
    // Convert base64 cell outputs to img src
    $base64_cells = array("image/png", "png");
    foreach($json["cells"] as $cellindex => &$cell) {
        foreach($cell["outputs"] as $outputindex => &$output) {

            foreach($output["data"] as $dataindex => &$data) {
                if(in_array($dataindex, $base64_cells)) {

                    $filepath = base64_img_to_webp($data, "png");
                    $output["data"] = array("text/markdown" => "<img src='" . $filepath . "' />");
                }
            }
        }
    }

    // If metadata cell exists, remove it
    if(0 === strcmp(rmnewline($json["cells"][0]["source"][0]), "%META")) {
        // Metadata cell is present, remove it
        array_shift($json["cells"]);
    }

    return json_encode($json);
}

function download_from_github($raw_url) {
    /**
     * Downloads a file from github
     *
     * @param $raw_url raw url to .ipynb file hosted on github
     * 
     * @return file content as string 
     */

    // Convert github url to raw.githubusercontent.com url
    $raw_url = str_replace("github.com", "raw.githubusercontent.com", $raw_url);
    $raw_url = str_replace("/blob/", "/", $raw_url);

    // Download file content from github
    $result = wp_remote_get($raw_url);
    return $result["body"];
}


function inject_notebook($atts) {
    /**
     * Loads and injects the necessary .js files, then downloads the notebook and injects it into the page
     *
     * @param array $atts Attribute array from wp shortcode. Should contain notebook url as first element
     * 
     * @return NULL
     */
    
    load_js_files();
    wp_localize_script('mylib', 'WPURLS', array( 'siteurl' => get_option('siteurl') ));

    add_action( 'wp_footer', function() use( $atts ){
        global $STORAGE_LOCATION;

        $cachefilename = md5(reset($atts)) . ".ipynbcache";

        // Check if file is older than $CACHE_FURATION_SECONDS. If it is: Remove the .ipynbcache file from disk
        global $CACHE_DURATION_SECONDS;
        if (time()-filemtime($STORAGE_LOCATION . $cachefilename) > $CACHE_DURATION_SECONDS) {
            // file older than $CACHE_FURATION_SECONDS, delete it
            unlink($STORAGE_LOCATION . $cachefilename);
        } else {
            echo "Render is " . (time()-filemtime($STORAGE_LOCATION . $cachefilename)) . " seconds old";
        }

        if(!file_exists($STORAGE_LOCATION . $cachefilename)) {

            $result = download_from_github(reset($atts));
            echo "Render is fresh";

            // Update post metadata
            parse_metadata(get_the_ID(), $result);

            global $STORAGE_LOCATION;
            // Create folder if not exists
            if (!file_exists($storage_location)) {
                 mkdir($STORAGE_LOCATION, 0777, true);
            }

            // Optimize json
            $optimized_json = optimize_json($result);

            // Persist the file to disk
            file_put_contents($STORAGE_LOCATION . $cachefilename, $optimized_json);

        } else {
            $optimized_json = file_get_contents($STORAGE_LOCATION . $cachefilename);
        }

        $jsontext = $optimized_json;
        $jsontext = str_replace("\n", "", $jsontext);
        $jsontext = json_encode($jsontext);
        ?>
            <script>
            (function() {
            render_notebook(<?php echo $jsontext; ?>);
            })();
            </script> 
        <?php
    }, 10000);
}


function load_js_files() {
    /**
     * Injects the js files into the document
     *
     * @return NULL
     */ 
    
    $plugin_url = plugin_dir_url( __FILE__ );

    $js_files = array(
        "js/vendor/es5-shim.min.js",
        "js/vendor/marked.min.js",
        "js/vendor/purify.min.js",
        "js/vendor/ansi_up.min.js",
        "js/vendor/prism.min.js",
        "js/vendor/katex.min.js",
        "js/vendor/katex-auto-render.min.js",
        "js/vendor/notebook.min.js"
    );

    foreach($js_files as $js_file) {
        wp_enqueue_script( uniqid(), $plugin_url . $js_file, array(), 1.0, true);
    }
    // nbpreview.js has to be last
    wp_enqueue_script( "nbpreview", $plugin_url . "js/nbpreview.js", array(), 1.0, true);
    wp_localize_script('nbpreview', 'WPURLS', array( 'siteurl' => get_option('siteurl') ));
}
 
// source https://gist.github.com/mrbobbybryant/a18588f86b12fa71224b
function parse_shortcode_atts( $content, $shortcode ) {

	//Returns a sting consisting of all registered shortcodes.
	$pattern = get_shortcode_regex();

	//Checks the post content to see if any shortcodes are present.
	$shortcodes = preg_match_all( '/'. $pattern .'/s', $content, $matches );

	//Check to see which key our Attributes are sotred in.
	$shortcode_key = array_search( $shortcode, $matches[2] );

	//Create an new array of atts for our shortcode only.
	$shortcode_atts[] = $matches[3][$shortcode_key];

	//Ensure we don't have an empty strings
	$shortcode_atts= array_filter( $shortcode_atts );

	if ( ! empty( $shortcode_atts ) ) {

		//Pull out shortcode attributes based on the above key.
		$shortcode_atts = shortcode_parse_atts( implode( ',', $shortcode_atts ) );

		//Remove random commas from last value
		$shortcode_atts = array_map( function ( $att ) {
			return $att = str_replace( ',', '', $att );
		}, $shortcode_atts );

		$tags = array();

		foreach ( $shortcode_atts as $atts ) {
			$temp             = explode( '=', $atts );
			$tags[ $temp[0] ] = str_replace( '"', '', $temp[1] );
		}
		return $tags;
	}

	//If no attributes are returned, then an ID Att isn't present.
	return false;
}

function rmnewline($string) {
    /**
     * Removes newlines from $string
     *
     * @param $string String to remove newlines from
     * 
     * @return $string without newlines
     */
    return preg_replace('/\s+/', ' ', trim($string));
}

function get_or_create_category($string) {
    $catid = get_cat_ID($string);
    if(0 === $catid) {
        wp_insert_term( $string, 'category');
        $catid = get_cat_ID($string);
    }
    return $catid;
}

// Source: https://stackoverflow.com/questions/2955251/php-function-to-make-slug-url-string
function slugify($text, string $divider = '-') {
  // replace non letter or digits by divider
  $text = preg_replace('~[^\pL\d]+~u', $divider, $text);

  // transliterate
  $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

  // remove unwanted characters
  $text = preg_replace('~[^-\w]+~', '', $text);

  // trim
  $text = trim($text, $divider);

  // remove duplicate divider
  $text = preg_replace('~-+~', $divider, $text);

  // lowercase
  $text = strtolower($text);

  if (empty($text)) {
    return 'n-a';
  }

  return $text;
}


function parse_metadata($post_id, $jsonstring = false) {
    if( ! ( wp_is_post_revision( $post_id) || wp_is_post_autosave( $post_id ) ) ) {

	$post_content = get_post($post_id)->post_content;

        if (false === strpos($post_content, '[ipynb') ) {
            // No ipynb shortcode is present in this post
            return;
        }
	    
        $result = parse_shortcode_atts($post_content, "ipynb");
        if(count($result) != 1) {
            // Can't work with multiple shortcodes, return
            return;
        }
        
        if(false === $jsonstring) {
            $notebook_url = array_key_first($result);
            $jsonstring = download_from_github($notebook_url);
        }
        $json = json_decode($jsonstring, true);

        if(0 != strcmp(rmnewline($json["cells"][0]["source"][0]), "%META")) {
            // No metadata cell present
            return;
        }

        // initialize $post_update with post id so wp_update_post can identity the post
        $post_update = array(
            'ID'         => $post_id,
        );

        // Iterate over every line in meta cell, parse key/value and insert value into post_update if key is recognized
        foreach($json["cells"][0]["source"] as $line) {
            $exploded = explode("=", $line);
            $key = $exploded[0];
            $value = $exploded[1];

            switch($key) {
                case "title":
                    $post_update += array("post_title" => $value);
                    $post_update += array("post_name" => slugify($value));
                    break;
                case "excerpt":
                    $post_update += array("post_excerpt" => $value);
                    break;
                case "tags":
                    $post_update += array("tags_input" => explode(",", $value));
                    break;
                case "categories": 
                    $catids = array_map("get_or_create_category", explode(",", $value));
                    $post_update += array("post_category" => $catids);
                    break;
            }
        }

        // wp_update_post calls save_post so we have to unhook it to avoid an infinite recusive loop
        remove_action( 'save_post', 'parse_metadata' );
        wp_update_post( $post_update );
        add_action( 'save_post', 'parse_metadata' );
    }

}

// Where to actually hook into wordpress

// Extract metadata on post save
add_action( 'save_post', 'parse_metadata' );

// Extract metadata when the shortcode is loaded
// If not loaded from cache, this will also call parse_metadata
// (in case the metadata changed)
add_shortcode('ipynb', 'inject_notebook', 10000);



