<?php

class ResizeImage
{
    public $source;
    public $destin;
    public $type;
    public $srcWidth;
    public $srcHeight;
    public $maxWidth  = 300;
    public $maxHeight = 300;

    private $header;
    private $img;

    /* Get the image source and set the destination file */

    public function __construct($source, $destin='')
    {
        $this->source = $source;
        $this->destin = $destin;
        if (!$destin)
            $this->destin = 'tmb_'.$source;
    }

    /* Resize with maxWidth and maxHeight */

    public function resize($width='', $height='')
    {
        /* Get the image type */
        list($this->srcWidth, $this->srcHeight, $this->type) = getimagesize($this->source);

        /* Check and Set the width and height */
        if ($width)  $this->maxWidth  = $width;
        if ($height) $this->maxHeight = $height;
        if ($this->srcWidth <= $this->maxWidth && $this->srcHeight <= $this->maxHeight) {
            @copy($this->source, $this->destin);
            return $this->destin;
        }
        $k = min($this->maxWidth/$this->srcWidth, $this->maxHeight/$this->srcHeight);
        $newWidth  = round($k * $this->srcWidth);
        $newHeight = round($k * $this->srcHeight);

        $newImg = imagecreatetruecolor($newWidth, $newHeight);

        /* Check if this image is GIF and preserve transparency */
        if ($this->type == IMAGETYPE_GIF) {
            $im = imagecreatefromgif($this->source);
            $trnprt_indx = imagecolortransparent($im);
            // If we have a specific transparent color
            if ($trnprt_indx >= 0) {
                // Get the original image's transparent color's RGB values
                $trnprt_color = imagecolorsforindex($im, $trnprt_indx);
                // Allocate the same color in the new image resource
                $trnprt_indx  = imagecolorallocate($newImg, $trnprt_color['red'], $trnprt_color['green'], $trnprt_color['blue']);
                // Completely fill the background of the new image with allocated color.
                imagefill($newImg, 0, 0, $trnprt_indx);
                // Set the background color for new image to transparent
                imagecolortransparent($newImg, $trnprt_indx);
            }
            imagecopyresampled($newImg, $im, 0, 0, 0, 0, $newWidth, $newHeight, $this->srcWidth, $this->srcHeight);
            imagegif($newImg, $this->destin);
        }

        /* Check if image jpeg */
        elseif ($this->type == IMAGETYPE_JPEG) {
            $im = imagecreatefromjpeg($this->source);
            imagecopyresampled($newImg, $im, 0, 0, 0, 0, $newWidth, $newHeight, $this->srcWidth, $this->srcHeight);
            imagejpeg($newImg, $this->destin);
        }

        /* Check if image is PNG and preserve transparency */
        elseif ($this->type == IMAGETYPE_PNG){
            $im = imagecreatefrompng($this->source);
            imagealphablending($newImg, false);
            imagesavealpha($newImg, true);
            $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127);
            imagefilledrectangle($newImg, 0, 0, $newWidth, $newHeight, $transparent);
            imagecopyresampled($newImg, $im, 0, 0, 0, 0, $newWidth, $newHeight, $this->srcWidth, $this->srcHeight);
            imagepng($newImg, $this->destin);
        }

        else {
            trigger_error('Unsupported filetype!', E_USER_WARNING);
            exit();
        }

        imagedestroy($im);
        imagedestroy($newImg);

        return $this->destin;
    }


}
