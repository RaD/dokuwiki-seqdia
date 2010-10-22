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

    function json2array($bad_json) {
      // {img: "?img=mscmO5omK", page: 0, numPages: 1, errors: []}
      $result = false;
      if ( preg_match('/{([^}]+)}/', $bad_json, $match) ) {
        $keywords = preg_split('/, +/', $match[1]);
        $result = array();
        foreach ($keywords as $item) {
          if ( preg_match('/([^:]+): +"(.+)"/', $item, $kw ) ) {
            $result[$kw[1]] = $kw[2];
          }
        }
      } else {
        dbglog('not match');
      }
      return $result;
    }

    /**
     * Run the diagram program
     */
    function _run($data,$cache) {
      global $conf;
      $conf['curl_timeout'] = 30;
      $conf['render_url'] = 'http://www.websequencediagrams.com/index.php';

      $post_params = array('style' => 'rose',
                           'message' => urlencode($data['data'])
                           );
      $post_params = sprintf('style=%s&message=%s', 'rose', urlencode($data['data']));

      $renderer = curl_init();
      dbglog($conf['render_url'], 'url');
      dbglog($post_params, 'uri');
      curl_setopt($renderer, CURLOPT_URL, $conf['render_url']);
      //curl_setopt($renderer, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data; charset=utf-8'));
      curl_setopt($renderer, CURLOPT_HEADER, false); // do not return http headers
      curl_setopt($renderer, CURLOPT_RETURNTRANSFER, true); // return the content of the call
      curl_setopt($renderer, CURLOPT_POST, true);
      curl_setopt($renderer, CURLOPT_POSTFIELDS, $post_params);
      //curl_setopt($renderer, CURLOPT_FOLLOWLOCATION, true);
      curl_setopt($renderer, CURLOPT_TIMEOUT, $conf['curl_timeout']);

      // get info
      $response = curl_exec($renderer);
      if ( 0 < curl_errno($renderer) ) {
        $info = curl_getinfo($renderer);
        $msg = sprintf('Took %s seconds to send a request to %s', $info['total_time'], $info['url']);
        dbglog($msg, 'error');
        $error_no = curl_errno($renderer);
        $error_desc = curl_error($renderer);
        dbglog(sprintf('[%s] %s', $error_no, $error_desc), 'error');
        dbglog('stop: 1');
        return false;
      }

      curl_close($renderer);

      $json = $this->json2array($response);

      $imgurl = sprintf('%s%s', $conf['render_url'], $json['img']);

      $renderer = curl_init();
      curl_setopt($renderer, CURLOPT_URL, $imgurl);
      curl_setopt($renderer, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($renderer, CURLOPT_BINARYTRANSFER, true);
      curl_setopt($renderer, CURLOPT_TIMEOUT, $conf['curl_timeout']);
      curl_setopt($renderer, CURLOPT_HEADER, false);

      $response = curl_exec($renderer);

      if ( 0 < curl_errno($renderer) ) {
        $info = curl_getinfo($renderer);
        $msg = sprintf('Took %s seconds to send a request to %s', $info['total_time'], $info['url']);
        dbglog($msg, 'error');
        $error_no = curl_errno($renderer);
        $error_desc = curl_error($renderer);
        dbglog(sprintf('[%s] %s', $error_no, $error_desc), 'error');
        dbglog('stop: 1');
        return false;
      }
      $fp = @fopen($cache, 'x');
      if ( ! $fp ) {
        dbglog('could not open file for writing: ' . $filename, $cache);
        return false;
      }
      fwrite($fp, $response);
      fclose($fp);

      curl_close($renderer);

      return true;
    }

}
