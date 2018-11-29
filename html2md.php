<?php

if (!function_exists('html2markdown')) {
	function html2markdown($html) {
		$parser = new HTML2MD($html);
		return $parser->get_markdown();
	}
}

class HTML2MD
{

	private $doc;
	
	public function __construct($html)
	{

		$html = preg_replace('~>\s+<~', '><', $html); # Strip white space between tags to ensure uniform output

		$this->doc = new DOMDocument();

		$this->doc->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>',  LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR );	# Hack to load utf-8 HTML (from http://bit.ly/pVDyCt )
		$this->doc->encoding = 'UTF-8';


	}

	public function get_markdown()
	{

		# Use the body tag as our root element
		$body = $this->doc->getElementsByTagName("body")->item(0);

		# For each element inside the body, find child elements and convert
		# those to markdown #text nodes, starting with the innermost element
		# and working towards the outermost element ($node).

		$this->convert_children($body);

		# The DOMDocument represented by $doc now consists of #text nodes, each containing a
		# markdown version of the original DOM node created by convert_to_markdown().

		# Return the <body> contents of $doc, first stripping html and body tags, the DOCTYPE
		# and XML encoding lines, then converting entities such as &amp; back to &.

		$markdown = $this->doc->saveHTML();
		// Double decode. http://www.php.net/manual/en/function.htmlentities.php#99984
        $markdown = html_entity_decode($markdown, ENT_QUOTES, 'UTF-8');
		$markdown = html_entity_decode($markdown, ENT_QUOTES, 'UTF-8');
  

		$markdown = preg_replace("/<!DOCTYPE [^>]+>/", "", $markdown);
		$unwanted = array('<html>','</html>','<body>','</body>', '<?xml encoding="UTF-8">', '&#xD;');
		$markdown = str_replace( $unwanted, '', $markdown);
        $markdown = trim($markdown, "\n\r\0\x0B");
		return $markdown;
	}

    # Convert child nodes from the outside in
	private function convert_children($node) {
		if (self::has_parent_code($node)) return;
		if ($node->hasChildNodes()) {
			for ($length = $node->childNodes->length, $i = 0; $i < $length; $i++) {
				$child = $node->childNodes->item($i);
				$this->convert_children($child);
			}
		}
		$this->convert_to_markdown($node);
	}


	# Convert the supplied element into its markdown equivalent,
	# then swap the original element in the DOM with the markdown
	# version as a #text node. This converts the HTML $doc into
	# markdown while retaining the nesting and order of all tags.

	private function convert_to_markdown($node)
	{

		$tag 	= $node->nodeName;	#the type of element, e.g. h1
		$value 	= $node->nodeValue;	#the value of that element, e.g. The Title

		switch ($tag)
		{
			case "p":
			case "pre":
				$markdown =  (trim($value)) ? rtrim($value) . PHP_EOL . PHP_EOL : '';
				break;
			case "h1":
				$markdown = "# ".$value . PHP_EOL . PHP_EOL;
				break;
			case "h2":
				$markdown = "## ".$value . PHP_EOL. PHP_EOL;
				break;
			case "h3":
				$markdown = "### ".$value . PHP_EOL . PHP_EOL;
				break;
			case "h4":
				$markdown = "#### ".$value . PHP_EOL . PHP_EOL;
				break;
			case "h5":
				$markdown = "##### ".$value . PHP_EOL . PHP_EOL;
				break;
			case "h6":
				$markdown = "###### ".$value . PHP_EOL . PHP_EOL;
				break;
			case "em":
			case "i":
				$markdown = "*".$value."*";
				break;
			case "strong":
			case "b":
				$markdown = "**".$value."**";
				break;
			case "hr":
				$markdown = "- - - - - -".PHP_EOL.PHP_EOL;
				break;
			case "br":
				$markdown = "  " . PHP_EOL;
				break;
			case "blockquote":
				$markdown =  $this->convert_blockquote($node);
				break;
			case "code":
				$markdown = $this->convert_code($node);
				break;
			case "ol":
			case "ul":
				$markdown = $value . PHP_EOL;
				break;
			case "li":
				$markdown = $this->convert_list($node);
				break;
			case "img":
				$markdown = $this->convert_image($node);
				break;
			case "a":
				$markdown = $this->convert_anchor($node);
				break;
			default:
				# C14N() is the XML canonicalization function (undocumented).
				# It returns the full content of the node, including surrounding tags.
				$markdown =   html_entity_decode(html_entity_decode($node->C14N()),ENT_QUOTES, 'UTF-8');

		}

		#Create a DOM text node containing the markdown equivalent of the original node
		$markdown_node = $this->doc->createTextNode($markdown);

		#Swap the old $node e.g. <h3>Title</h3> with the new $markdown_node e.g. ###Title
		$node->parentNode->replaceChild($markdown_node, $node);


	}


	private function convert_image($node)
	{

		$src 	= $node->getAttribute('src');
		$alt 	= $node->getAttribute('alt');

		$markdown = '!['.$alt.']('.$src.')';


		return $markdown;

	}


	private function convert_anchor($node)
	{

		$href 	= $node->getAttribute('href');
		$text 	= $node->nodeValue;


		$markdown = '['.$text.']('.$href.')';

        $next_node_name = $this->get_next_node_name($node);
		if ($next_node_name == 'a') {
            $markdown = $markdown . ' ';
        }
		return $markdown;

	}



	private function convert_list($node)
	{

		#If parent is an ol, use numbers, otherwise, use dashes
		$list_type 	= $node->parentNode->nodeName;
		$value		= $node->nodeValue;

		if ($list_type == "ul"){

			$markdown = "- ".$value . PHP_EOL;

		} else {

			$number = $this->get_list_position($node);
			$markdown = $number.". ".$value . PHP_EOL;

		}

		return $markdown;

	}
    # Don't convert code that's inside a code block
	private static function has_parent_code($node) {
		for ($p = $node->parentNode; $p != false; $p = $p->parentNode) {
			if (is_null($p)) return false;
			if ($p->nodeName == 'code') return true;
		}
		return false;
	}


	private function convert_code($node)
	{

		# Store the content of the code block in an array, one entry for each line

		$markdown = '';

		$code_content = html_entity_decode($node->C14N(), ENT_QUOTES, 'UTF-8');
		$code_content = str_replace(array("<code>","</code>"), "", $code_content);

		$lines = preg_split( '/\r\n|\r|\n/', $code_content );
		$total = count($lines);

		# If there's more than one line of code, prepend each line with four spaces and no backticks.
		if ($total > 1){

			# Remove the first and last line if they're empty
			$first_line	= trim($lines[0]);
			$last_line	= trim($lines[$total-1]);
			$first_line = trim($first_line, "&#xD;"); //trim XML style carriage returns too
			$last_line	= trim($last_line, "&#xD;");

			if ( empty( $first_line ) )
				array_shift($lines);

			if ( empty( $last_line ) )
				array_pop($lines);

			$count = 1;
			foreach ($lines as $line) {

				$line = str_replace('&#xD;', '', $line);
				$markdown .= "    ".$line;
				// Add newlines, except final line of the code
				if ($count != $total) $markdown .= PHP_EOL;
				$count++;
			}
			$markdown .= PHP_EOL;

		} else { # There's only one line of code. It's a code span, not a block. Just wrap it with backticks.

            $markdown .= "`".$lines[0]."`";

		}

		return $markdown;

	}



	private function convert_blockquote($node)
	{

		# Contents should have already been converted to markdown by this point,
		# so we just need to add ">" symbols to each line.

		$markdown = '';

		$quote_content = trim($node->nodeValue);

		$lines = preg_split( '/\r\n|\r|\n/', $quote_content );
		$lines = array_filter($lines); //strips empty lines

		foreach($lines as $line){
			$markdown .= "> ".$line. PHP_EOL;
		}

		return PHP_EOL. $markdown ;
	}



	#
	# Helper methods
	#

	# Returns numbered position of an <li> inside an <ol>
	private function get_list_position($node)
	{

		# Get all of the li nodes inside the parent
		$list_nodes  = $node->parentNode->childNodes;
		$total_nodes = $list_nodes->length;

		$position = 1;

		# Loop through all li nodes and find the one we passed
		for ($a = 0; $a < $total_nodes; $a++)
		{
			$current_node = $list_nodes->item($a);

			if ($current_node->isSameNode($node))
				$position = $a + 1;

		}

		return $position;

	}

    
    private function get_next_node_name($node)
    {
        $next_node_name = null;

        $current_position = $this->get_position($node);
        $next_node = $node->parentNode->childNodes->item($current_position);

        if ($next_node)
            $next_node_name = $next_node->nodeName;

        return $next_node_name;
    }

    private function get_position($node)
    {
        // Get all of the nodes inside the parent
        $list_nodes = $node->parentNode->childNodes;
        $total_nodes = $list_nodes->length;

        $position = 1;

        // Loop through all nodes and find the given $node
        for ($a = 0; $a < $total_nodes; $a++) {
            $current_node = $list_nodes->item($a);

            if ($current_node->isSameNode($node))
                $position = $a + 1;
        }

        return $position;
    }



}