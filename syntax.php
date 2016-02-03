<?php
/**
 * seqdia-Plugin: Parses seqdia-blocks
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Carl-Christian Salvesen <calle@ioslo.net>
 * @author     Andreas Gohr <andi@splitbrain.org>
 * @author     Ruslan Popov <ruslan.popov@gmail.com>
 * @author     Willi Schönborn <w.schoenborn@googlemail.com>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
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
        return 200;
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
    function handle($match, $state, $pos, Doku_Handler $handler) {
        $info = $this->getInfo();

        // prepare default data
        $return = array(
                        'data'      => '',
                        'width'     => 0,
                        'height'    => 0,
                        'style'    => 'default',
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
        if(preg_match('/\b(default|rose|qsd|napkin|vs2010|mscgen|omegapple|modern-blue|earth|roundgreen)\b/i',$conf,$match)){
             $return['style'] = strtolower($match[1]);
        }
        if(preg_match('/\bwidth=([0-9]+)\b/i', $conf,$match)) $return['width'] = $match[1];
        if(preg_match('/\bheight=([0-9]+)\b/i', $conf,$match)) $return['height'] = $match[1];

        $input = join("\n",$lines);
        $return['md5'] = md5($input); // we only pass a hash around

        // store input for later use
        io_saveFile($this->_cachename($return,'txt'),$input);

        return $return;
    }

    /**
     * Cache file is based on parameters that influence the result image
     */
    function _cachename($data,$ext){
        unset($data['width']);
        unset($data['height']);
        unset($data['align']);
        return getcachename(join('x',array_values($data)),'.seqdia.'.$ext);
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $R, $data) {
        if($format == 'xhtml'){
            $img = DOKU_BASE.'lib/plugins/seqdia/img.php?'.buildURLparams($data);
            $R->doc .= '<img src="'.$img.'" class="media'.$data['align'].'" alt=""';
            if($data['width'])  $R->doc .= ' width="'.$data['width'].'"';
            if($data['height']) $R->doc .= ' height="'.$data['height'].'"';
            if($data['align'] == 'right') $R->doc .= ' align="right"';
            if($data['align'] == 'left')  $R->doc .= ' align="left"';
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
     * Return path to the rendered image on our local system
     */
    function _imgfile($data){
        $cache  = $this->_cachename($data,'png');

        // create the file if needed
        if(!file_exists($cache)){
            $in = $this->_cachename($data,'txt');
            $ok = $this->_remote($data,$in,$cache);
            if(!$ok) return false;
            clearstatcache();
        }

        // resized version
        if($data['width']){
            $cache = media_resize_image($cache,'png',$data['width'],$data['height']);
        }

        // something went wrong, we're missing the file
        if(!file_exists($cache)) return false;

        return $cache;
    }

    /**
     * Render the output remotely at ditaa.org
     */
    function _remote($data,$in,$out){
        if(!file_exists($in)){
            if($conf['debug']){
                dbglog($in,'no such seqdia input file');
            }
            return false;
        }

        $http = new DokuHTTPClient();
        $http->timeout=30;

        $pass = array();
        $pass['style']   = $data['style'];
        $pass['message'] = io_readFile($in);

        $result = $http->post('http://www.websequencediagrams.com/index.php',$pass);
        if(!$result) return false;

        $json = new JSON(JSON_LOOSE_TYPE);
        $json->skipnative = true;
        $json_data = $json->decode($result);

        $img = $http->get('http://www.websequencediagrams.com/index.php'.$json_data['img']);
        if(!$img) return false;

        return io_saveFile($out,$img);
    }

}
