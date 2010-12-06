<?
/*
  Copyright (c) 2005 James Baicoianu

  This library is free software; you can redistribute it and/or
  modify it under the terms of the GNU Lesser General Public
  License as published by the Free Software Foundation; either
  version 2.1 of the License, or (at your option) any later version.

  This library is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
  Lesser General Public License for more details.

  You should have received a copy of the GNU Lesser General Public
  License along with this library; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class ComponentManager extends Component {
  public $components = array();
  private $dispatchargs = array();

  protected static $instance;
  public static function singleton($args=NULL) { $name = __CLASS__; if (!self::$instance) { self::$instance = new $name($args); } return self::$instance; }

  function ComponentManager(&$parent) {
    $this->Component("", $parent);
  }

  function Dispatch($page=NULL, $args=NULL) {
    $alternateret = $this->HandleDispatchArgs($args);
    $outputtype = "html";

    $ret["page"] = $page;

    if ($page == "/") {
      $ret["type"] = $outputtype;
      $ret["component"] = "index";
/*
      if ($component = $this->Get("index")) {
        $ret["content"] = $component->HandlePayload($_REQUEST, $outputtype);
      }
*/
      $ret["content"] = self::fetch("index", $_REQUEST, $outputtype);
    } else if (preg_match("|^/((?:[^./]+/?)*)(?:\.(.*))?$|", $page, $m)) {
      $componentname = str_replace("/", ".", $m[1]);
      $outputtype = (isset($m[2]) ? $m[2] : "html");

      $ret["component"] = $componentname;
      $ret["type"] = $outputtype;

      if ($component = $this->Get($componentname)) {
        $componentargs = (!empty($this->dispatchargs[$componentname]) ? array_merge_recursive($args, $this->dispatchargs[$componentname]) : $args);
        $ret["content"] = $component->HandlePayload($componentargs, $outputtype);
      }
    }

    if ($ret['content'] instanceOf ComponentResponse) {
      $output = $ret['content']->getOutput($outputtype);
      $ret['responsetype'] = $output[0];
      $ret['content'] = $output[1];
    }
    // TODO - handle redirects and postprocessing for different output types here
    return $ret;
  }

  function GetDispatchArgs($name, $args=NULL) {
    $ret = $args;
    if (!empty($this->dispatchargs[$name]))
      if ($args === NULL)
        $ret = $this->dispatchargs[$name];
      else {
        $ret = array_merge_recursive($args, $this->dispatchargs[$name]);

        //print_pre("DISPATCH:");
        //print_pre($ret);
      }
    return $ret;
  }
  function HandleDispatchArgs($args) {
    if (!empty($args["_predispatch"])) {
      foreach ($args["_predispatch"] as $k=>$v) {
        if ($dispatchcomponent = $this->Get($v)) {
          $noret = $dispatchcomponent->HandlePayload($args, "dispatch");
        }
      }
    }

    // _dispatchargs are stored and merged in when this component is called later
    if (!empty($args["_dispatchargs"])) {
      foreach ($args["_dispatchargs"] as $k=>$v) {
        // TODO - this currently only works for unique elements (ie, all html.forms on a page).  
        //        We Should check for multidimensionality once placement support is added

        if (!empty($args[$k]))
          $this->dispatchargs[$v][$k] = $args[$k];
      }
    }
  }

  static public function fetch($componentname, $args=array(), $output="inline") {
    $ret = NULL;
    $componentmanager = self::singleton();
    $component = $componentmanager->Get($componentname);
    if (!empty($component)) {
      $ret = $component->HandlePayload($args, $output);
      if ($ret instanceOf ComponentResponse) {
        $output = $ret->getOutput($output);
        //$this->root->response["type"] = $output[0];
        $ret = $output[1];
      }
    }
    return $ret;
  }
}

class ComponentResponse implements ArrayAccess {
  public $data = array();
  private $template;
  
  function __construct($template=NULL, $data=NULL) {
    $this->template = $template;
    $this->data = $data;
  }
  
  function offsetExists($name) {
    return isset($this->data[$name]);
  }
  function offsetGet($name) {
    return $this->data[$name];
  }
  function offsetSet($name, $value) {
    $this->data[$name] = $value;
  }
  function offsetUnset($name) {
    unset($this->data[$name]);
  }
  function getTemplate() {
    return $this->template;
  }

  function getOutput($type) {
    $ret = array("text/html", NULL);;
    $tplmgr = TemplateManager::singleton();
    switch($type) {
      case 'ajax':
        $ret = array("text/xml", $tplmgr->GenerateXML($this->data));
        break;
      case 'json':
        $ret = array("application/javascript", $tplmgr->GenerateJavascript($ret));
        break;
      case 'js':
        $ret = array("application/javascript", json_indent(json_encode($this)) . "\n");
        break;
      case 'txt':
        $ret = array("text/plain", $tplmgr->GenerateHTML($tplmgr->GetTemplate($this->template, NULL, $this->data)));
        break;
      case 'xml':
        $ret = array("application/xml", object_to_xml($this, "response"));
        break;
      case 'data':
        $ret = array("", $this->data);
        break;
      case 'componentresponse':
        $ret = array("", $this);
        break;
      case 'html':
        $framecomponent = any(ConfigManager::get("page.frame"), "html.page");
        $vars["content"] = $this;
        $ret = array("text/html", ComponentManager::fetch($framecomponent, $vars, "inline"));
        break;
      default:
        $ret = array("text/html", $tplmgr->GetTemplate($this->template, NULL, $this->data));
    }
    if (!empty($this->prefix)) {
      $ret[1] = $this->prefix . $ret[1];
    }
    return $ret;
  }
  function prepend($str) {
    $this->prefix = $str;
  }
}