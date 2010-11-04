<?php
/**
 * seqdia-Plugin: Parses seqdia-blocks
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Carl-Christian Salvesen <calle@ioslo.net>
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Ruslan Popov <ruslan.popov@gmail.com>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
require_once(DOKU_INC.'inc/init.php');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

$media_inc = '../../../inc/media.php';
if (file_exists($media_inc)) {
  require_once $media_inc;
 }

require_once 'HTTP/Request.php';

$DOKU_DIR = realpath(dirname(__FILE__).'/../../../');

require_once(sprintf('%s/%s', $DOKU_DIR, 'inc/JSON.php'));

class syntax_plugin_seqdia extends DokuWiki_Syntax_Plugin {

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'normal';
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 100;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<seqdia.*?>\n.*?\n</seqdia>',$mode,'plugin_seqdia');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler) {
        $info = $this->getInfo();

        // prepare default data
        $return = array(
                        'data'      => '',
                        'width'     => 0,
                        'height'    => 0,
                        'style'    => 'rose',
                        'align'     => '',
                        'version'   => $info['date'], //force rebuild of images on update
                       );

        // prepare input
        $lines = explode("\n",$match);
        $conf = array_shift($lines);
        array_pop($lines);

        // match config options
        if(preg_match('/\b(left|center|right)\b/i',$conf,$match)) $return['align'] = $match[1];
        if(preg_match('/\b(\d+)x(\d+)\b/',$conf,$match)){
            $return['width']  = $match[1];
            $return['height'] = $match[2];
        }
        // if(preg_match('/\b(dot|neato|twopi|circo|fdp)\b/i',$conf,$match)){
        //     $return['layout'] = strtolower($match[1]);
        // }
        if(preg_match('/\bwidth=([0-9]+)\b/i', $conf,$match)) $return['width'] = $match[1];
        if(preg_match('/\bheight=([0-9]+)\b/i', $conf,$match)) $return['height'] = $match[1];

        $return['data'] = join("\n",$lines);

        return $return;
    }

    /**
     * Create output
     *
     * @todo latex support?
     */
    function render($format, &$R, $data) {
        if($format == 'xhtml'){
            $img = $this->_imgurl($data);
            $R->doc .= '<img src="'.$img.'" class="media'.$data['align'].'" alt=""';
            if($data['width'])  $R->doc .= ' width="'.$data['width'].'"';
            if($data['height']) $R->doc .= ' height="'.$data['height'].'"';
            if($data['align'] == 'right') $ret .= ' align="right"';
            if($data['align'] == 'left')  $ret .= ' align="left"';
            $R->doc .= '/>';
            return true;
        }elseif($format == 'odt'){
            $src = $this->_imgfile($data);
            $R->_odtAddImage($src,$data['width'],$data['height'],$data['align']);
            return true;
        }
        return false;
    }

    /**
     * Build the image URL using either our own generator
     */
    function _imgurl($data){
      $img = DOKU_BASE.'lib/plugins/seqdia/img.php?'.buildURLparams($data,'&');
      return $img;
    }

    /**
     * Return path to created diagram graph (local only)
     */
    function _imgfile($data){
        $w = (int) $data['width'];
        $h = (int) $data['height'];
        unset($data['width']);
        unset($data['height']);
        unset($data['align']);

        $cache = getcachename(join('x',array_values($data)),'seqdia.png');

        // create the file if needed
        if(!file_exists($cache)){
            $this->_run($data,$cache);
            clearstatcache();
        }

        // resized version
        if($w) $cache = media_resize_image($cache,'png',$w,$h);
        return $cache;
    }

    /**
     * Run the diagram program
     */
    function _run($data,$cache) {
      global $conf;
      $conf['render_url'] = 'http://www.websequencediagrams.com/index.php';

      $request =& new HTTP_Request($conf['render_url']);
      $request->setMethod(HTTP_REQUEST_METHOD_POST);
      $request->addPostData('style', 'rose');
      $request->addPostData('message', $data['data']);
      if (!PEAR::isError($request->sendRequest())) {
        $response = $request->getResponseBody();
      } else {
        $response = "";
      }

      $json = new JSON(JSON_LOOSE_TYPE);
      $json_data = $json->decode($response);

      $imgurl = sprintf('%s%s', $conf['render_url'], $json_data['img']);

      $request->setMethod(HTTP_REQUEST_METHOD_GET);
      $request->setURL($imgurl);
      $request->clearPostData();
      if (!PEAR::isError($request->sendRequest())) {
        $response = $request->getResponseBody();
        $fp = @fopen($cache, 'x');
        if ( ! $fp ) {
          dbglog('could not open file for writing: ' . $filename, $cache);
          return false;
        }
        fwrite($fp, $response);
        fclose($fp);
      } else {
        $response = "";
      }
      return true;
    }
}
