<?

class Component_elation extends Component {
  function init() {
  }

  function controller_elation($args, $output="inline") {
    $cfg = ConfigManager::singleton();
    $introenabled = any(ConfigManager::get("elation.intro.enabled"), array_get($cfg->servers, "elation.intro.enabled"), true);
    if ($introenabled && $introenabled !== "false") {
      $vars["inspect"] = "demo.blog";
      if (!empty($args["inspect"]) && strpos($args["inspect"], "/..") === false) {
        $vars["inspect"] = $args["inspect"];
        $vars["file"] = any($args["file"], NULL);
      }
      return $this->GetComponentResponse("./elation.tpl", $vars);
    }
    return "";
  }
  function controller_debug($args) {
    $user = User::singleton();
    if ($user->isLoggedIn() && ($user->HasRole("ADMIN") || $user->HasRole("QA"))) {
      return $this->GetComponentResponse("./debug.tpl", $vars);
    }
    return "";
  }
  function controller_logger($args) {
    return Logger::Display(E_ALL);
  }
  function controller_profiler($args) {
    return Profiler::Display(E_ALL);
  }
  function controller_settings(&$args, $output="inline") {
    //$cfg = $this->root->cfg->FlattenConfig($this->root->cfg->LoadServers($this->root->locations["config"] . "/servers.ini", false));
    $cfg = $this->root->cfg->FlattenConfig($this->root->cfg->GetRoleSettings($this->root->cfg->role));
    
    $vars["tfdev"] = (!empty($_COOKIE["tf-dev"]) ? json_decode($_COOKIE["tf-dev"], true) : array());

    if (!empty($args["clear"])) {
      Logger::Info("Cleared dev settings");
      setcookie("tf-dev", NULL, 0, "/");
      $vars["tfdev"] = array();
      $this->root->cfg->servers = $cfg;
    }

    if (!empty($args["settings"])) {
      $diff_orig = array_diff_assoc_recursive($args["settings"], $cfg);
      $diff_curr = array_diff_assoc_recursive($args["settings"], $this->root->cfg->FlattenConfig($this->root->cfg->servers));

      //$vars["tfdev"]["serveroverrides"] = $diff_orig;
      $cookiesettings = array();
      if (!empty($_COOKIE["tf-dev"])) {
        $tmpsettings = json_decode($_COOKIE["tf-dev"], true);
        $cookiesettings = (!empty($tmpsettings["serveroverrides"]) ? $tmpsettings["serveroverrides"] : array());
      }
      $vars["tfdev"]["serveroverrides"] = array_merge($cookiesettings, $diff_curr);
      setcookie("tf-dev", json_encode($vars["tfdev"]), time() + 86400*365, "/"); // dev cookie persists for a year
      
      if (!empty($diff_curr)) {
        foreach ($diff_curr as $setting=>$value) {
          Logger::Info("Override setting: $setting = '$value'");
          $this->root->cfg->AppendSetting($this->root->cfg->servers, $setting, $value);
        }
      } else {
        Logger::Error("No config differences!");
      }

    }

    $vars["settings"] = $this->root->cfg->FlattenConfig($this->root->cfg->servers);
    $vars["container"] = ($output == "html");
    $ret = $this->GetComponentResponse("./settings.tpl", $vars);

    if ($output == "ajax") {
      $ret = array("tf_debug_tab_settings" => $ret);
    }
    return $ret;
  }
  function controller_inspect($args, $output="inline") {
    $user = User::singleton();
    if (!$user->HasRole("inspect")) return;

    $vars["component"] = $args["component"];
    $vars["componentdir"] = "./components/" . implode("/components/", explode(".", $vars["component"]));
    $vars["file"] = $args["file"];
    if (!empty($vars["component"]) && dir_exists_in_path($vars["componentdir"])) {
      $vars["files"] = $this->getDirContents($vars["componentdir"]);
    }
    return $this->GetComponentResponse("./inspect.tpl", $vars);
  }
  function controller_inspect_directory($args) {
    $user = User::singleton();
    if (!$user->HasRole("inspect")) return;

    $vars["dir"] = $args["dir"];
    $vars["fullname"] = any($args["parentdir"], ".") . (!empty($args["dirname"]) ? "/" . $args["dirname"] : "");
    return $this->GetComponentResponse("./inspect_directory.tpl", $vars);
  }
  function controller_inspect_file($args) {
    $user = User::singleton();
    if (!$user->HasRole("inspect")) return;

    $vars["component"] = $args["component"];
    $vars["componentdir"] = "./components/" . implode("/components/", explode(".", $vars["component"]));
    $vars["file"] = $args["file"];
    $vars["filetype"] = "unknown";
    if (preg_match("/\.\/.*\.(.*?)$/", $vars["file"], $m)) {
      $vars["filetype"] = $m[1];
    }
    if (!empty($vars["file"]) && strpos($vars["file"], "/../") === false) {
      $vars["fullname"] = $vars["componentdir"] . "/" . $vars["file"];
      if (($path = file_exists_in_path($vars["fullname"])) !== false) {
        switch ($vars["filetype"]) {
          case 'php':
            $vars["contents"] = str_replace("\n", "", highlight_string(file_get_contents($path . "/" . $vars["fullname"]), true));
            break;
          default:
            $vars["contents"] = htmlspecialchars(file_get_contents($path . "/" . $vars["fullname"]));
        }
      }
    } else {
      $vars["contents"] = $args["defaultcontent"];
    }
    return $this->GetComponentResponse("./inspect_file.tpl", $vars);
  }

  function controller_inspect_component($args, $output="inline") {
    $user = User::singleton();
    if (!$user->HasRole("inspect")) return;

    $components = explode(",", $args["components"]);
    sort($components);
    $vars["components"] = array();
    foreach ($components as $component) {
      $fname = "components/" . implode("/components/", explode(".", $component)) . "/" . str_replace(".", "_", $component) . ".php";
      if ($path = file_exists_in_path($fname)) {
        $fcontents = file_get_contents("$path/$fname");
        $funcs = $this->parseControllerFunctions($fcontents, false);
        if (!empty($funcs)) {
          foreach ($funcs as $func) {
            $vars["components"][] = $this->parseControllerArguments($component, $func);
          }
        }
      }
    }
    return $this->GetComponentResponse("./inspect_component.tpl", $vars);
  }
  function parseControllerFunctions($fcontents, $debug=false) {
    $lastpos = 0;
    $funcs = array();
    $len = strlen($fcontents);
    while (($pos = strpos($fcontents, "function"." controller_", $lastpos)) !== false) { 
      // This should probably use parse_tokens() since we're calling it anyway. 
      // The weird string-concat bit above is so we don't match ourselves...
      if ($debug) print "start from $pos\n";
      $state = array(
        "{" => false,
        "(" => false,
        "\"" => false,
        "\'" => false,
        "commentline" => false,
        "comment" => false,
        "string" => false,
      );
      //$i = strpos($fcontents, "{", $pos); // find opening {
      $i = $pos;
      $funcdepth = 0;
      do {
//print "funcdepth at $i (" . $fcontents[$i] . ") is $funcdepth\n";
        $c = $fcontents[$i];
        $cbefore = $fcontents[$i-1];

        // First handle comment state, if we're not inside of a string
        if (!$state["string"]) {
          if ($c == "/") {
            if ($cbefore == "/" && !$state["comment"]) { 
              $state["commentline"] = true; // start single-line comment
            } else if ($cbefore == "*" && $state["comment"]) {
              $state["comment"] = false; // end multi-line comment
            }
          } else if ($c == "*" && $cbefore == "/") {
            $state["comment"] = true; // start multi-line comment
          }
          if ($c == "\n" && $state["commentline"]) {
            $state["commentline"] = false; // reset single-line comment at end of line
          }
        }

        // Now that we know comment state, only process control characters if we're not inside of one
        if (!($state["comment"] || $state["commentline"])) {
          if ($c == "{" && !($state["\""] || $state["\'"])) {
            $state["{"] = $state["{"] + 1;
            if ($debug) print $c . "[+ " . $state["{"] . "]";
          } else if ($c == "}" && !($state["\""] || $state["\'"])) {
            $state["{"]--;
            if ($debug) print $c . "[- " . $state["{"] . "]";
          } else if ($c == "(" && !($state["\""] || $state["\'"])) {
            $state["("] = $state["("] + 1;
            if ($debug) print $c . "[+ " . $state["("] . "]";
          } else if ($c == ")" && !($state["\""] || $state["\'"])) {
            $state["("]--;
            if ($debug) print $c . "[- " . $state["("] . "]";
          } else if (($c == "\"" && $cbefore != "\\" && $state["\'"] == 0) || ($c == "\'" && $cbefore != "\\" && $state["\""] == 0)) {
            if ($debug) print (!$state[$c] ? "^".$c : $c."_");
            $state[$c] = !$state[$c];
            $state["string"] = $state["\""] || $state["\'"];
          } else {
            if ($debug) print $c;
          }
        }
        $i++;
      } while (($state["{"] === false || $state["{"] > 0) && $i < $len);
      if ($debug) print "NEXT FUNC\n";
      $funcs[] = substr($fcontents, $pos, ($i - $pos));
      $lastpos = $i;
    }
    return $funcs;
  }
  function parseControllerArguments($component, $func) {
    $funcname = "";
    $tokens = token_get_all('<?' . $func . '?>');
    $args = array();
    for ($j = 0; $j < count($tokens); $j++) {
      if (is_array($tokens[$j])) {
        //printf("  - %s(%d): %s\n", token_name($tokens[$j][0]), $tokens[$j][0], $tokens[$j][1]);
        if ($tokens[$j][0] == T_FUNCTION && $tokens[$j+1][0] == T_WHITESPACE && $tokens[$j+2][0] == T_STRING) {
          $funcname = $tokens[$j+2][1];
        }
        if ($tokens[$j][0] == T_VARIABLE && $tokens[$j][1] == "\$args") {
          if ($tokens[$j+1] == "[" && $tokens[$j+3] == "]") {
            //print "ARG: " . $tokens[$j+2][1] . "\n";
            $args[] = substr($tokens[$j+2][1], 1, strlen($tokens[$j+2][1]) - 2);
          }
        }
      } else {
        //printf("  - %s\n", $tokens[$j]);
      }
    }
    $fullname = $component . "." . str_replace("controller_", "", $funcname);
    $args = array_unique($args);
    sort($args);
    $cdata = array("name" => $fullname);
    if (!empty($args)) {
      $cdata["args"] = $args;
    }
    return $cdata;
  }

  function getDirContents($dir) {
    $ret = array();
    if (($path = dir_exists_in_path($dir)) !== false) {
      $dh = opendir($path . "/" . $dir);
      while (($file = readdir($dh)) !== false) {
        if ($file[0] != '.') {
          if (is_dir($path . "/" . $dir . "/" . $file)) {
            $ret[$file] = $this->getDirContents($dir . "/" . $file);
            //$ret[$file] = $dir . "/" . $file;
          } else {
            $ret[$file] = $file;
          }
        }
      }
    }
    ksort($ret);
    return $ret;
  }

  function controller_memcache(&$args, $output="inline") {
    $vars["admin"] = false;
    $user = User::singleton();
    if ($user->isLoggedIn() && ($user->HasRole("ADMIN"))) {
      if (!empty($args["memcacheaction"])) {
        if ($args["memcacheaction"] == "delete" && !empty($args["memcachekey"])) {
          DataManager::CacheClear($args["memcachekey"]);
          $vars["tf_debug_memcache_status"] = "Deleted key '{$args['memcachekey']}'";
        } else if ($args["memcacheaction"] == "flush" && !empty($args["memcachetype"])) {
          $data = DataManager::singleton();
          if (!empty($data->caches["memcache"][$args["memcachetype"]])) {
            if ($data->caches["memcache"][$args["memcachetype"]]->flush()) {
              $vars["tf_debug_memcache_status"] = "Cache flushed successfully (" . $args["memcachetype"] . ")";
            } else {
              $vars["tf_debug_memcache_status"] = "FAILED TO FLUSH CACHE: " . $args["memcachetype"];
            }
          } else {
            $vars["tf_debug_memcache_status"] = "ERROR: Unknown memcache type '" . $args["memcachetype"] . "'";
          }
        }
      }
      $vars["admin"] = true;
    }

    if (!empty($data->caches["memcache"]["session"])) {
      $vars["stats"]["session"] = $data->caches["memcache"]["session"]->getExtendedStats();
    }
    if (!empty($data->caches["memcache"]["data"])) {
      $vars["stats"]["data"] = $data->caches["memcache"]["data"]->getExtendedStats();
    }

    if ($output == "ajax") {
      $vars = array("tf_debug_tab_memcache" => $this->GetTemplate("./memcache.tpl", $vars));
    }
    return $this->GetComponentResponse("./memcache.tpl", $vars);
  }
  function controller_apc(&$args, $output="inline") {
    $user = User::Singleton();
    if (!($user->isLoggedIn() && ($user->HasRole("ADMIN")))) 
      return;

    $vars["args"] = $args;

    if (!empty($args["flush"])) {
      apc_clear_cache();
      $vars["message"] = "APC cache cleared";
    }

    $vars["apc"]["smainfo"] = apc_sma_info();
    $vars["apc"]["cacheinfo"] = apc_cache_info();
    $vars["time"] = time();

    $nseg = $freeseg = $fragsize = $freetotal = 0;
    for($i=0; $i<$vars["apc"]["smainfo"]['num_seg']; $i++) {
      $ptr = 0;
      foreach($vars["apc"]["smainfo"]['block_lists'][$i] as $block) {
        if ($block['offset'] != $ptr) {
          ++$nseg;
        }
        $ptr = $block['offset'] + $block['size'];
        /* Only consider blocks <5M for the fragmentation % */
        if($block['size']<(5*1024*1024)) $fragsize+=$block['size'];
        $freetotal+=$block['size'];
      }
      $freeseg += count($vars["apc"]["smainfo"]['block_lists'][$i]);
    }

    if ($freeseg > 1) {
      $vars["frag"] = sprintf("%.2f%% (%s out of %s in %d fragments)", ($fragsize/$freetotal)*100,bsize($fragsize),bsize($freetotal),$freeseg);
    } else {
      $vars["frag"] = "0%";
    }


    if ($output == "ajax") {
      $vars = array("tf_debug_tab_apc" => $this->GetTemplate("./apc.tpl", $vars));
    }
    return $this->GetComponentResponse("./apc.tpl", $vars);
  }
  function controller_abtests($args, $output="inline") {
    $user = User::Singleton();
    if (!($user->isLoggedIn() && ($user->HasRole("ADMIN")))) {
      return ComponentManager::fetch("elation.accessviolation", NULL, "componentresponse");
    }
    $data = DataManager::singleton();
    $req = $this->root->request['args'];
    $vars['err_msg'] = "";
    if ($req['save_scope']) {
      // prepare to save new abtest - make sure we are not creating an active collision
      if ($req['status'] == 'active') {
        $sql = "SELECT * FROM userdata.abtest
          WHERE status='active'
          AND cobrand=:cobrand
          AND effective_dt != :effective_dt";
        if ($req['save_scope'] != 'all') $sql .= " AND role = :role";
        $query = DataManager::Query("db.abtests.abtest:nocache",
                              $sql,
                              array(
                                ":cobrand"=>$req['cobrand'],
                                ":effective_dt"=>$req['effective_dt'],
                                ":role"=>$req['role'])
                             );
        if ($query->results) $vars['err_msg'] = "***Save Aborted -- Active Status Collision -- ".$req['cobrand']." ".$query->results[0]->effective_dt;
      }
      if (!$vars['err_msg']) {
      // write new abtest group to database
        $roles=array($req['role']);
        if ($req['save_scope'] == 'all') $roles=array('dev', 'test', 'live', 'elation');
        foreach ($roles as $role) {
          DataManager::Query("db.abtests.abtest:nocache",
                        "DELETE FROM userdata.abtest
                          WHERE effective_dt=:effective_dt
                          AND cobrand=:cobrand
                          AND role=:role",
                        array(
                            ":effective_dt"=>$req["effective_dt"],
                            ":cobrand"=>$req["cobrand"],
                            ":role"=>$role)
                       );
          foreach ($req['version'] as $k=>$v) {
            DataManager::Query("db.abtests.abtest:nocache",
                         "INSERT INTO userdata.abtest
                            SET version=:version,
                               percent=:percent,
                                effective_dt=:effective_dt,
                                duration_days=:duration_days,
                                status=:status,
                                cobrand=:cobrand,
                                config=:config,
                                role=:role,
                                is_base=:is_base",
                          array(
                              ":version"=>$v,
                              ":percent"=>$req['percent'][$k],
                              ":effective_dt"=>$req['effective_dt'],
                              ":duration_days"=>$req['duration'],
                              ":status"=>$req['status'],
                              ":cobrand"=>$req['cobrand'],
                              ":config"=>$req['config'][$k],
                              ":role"=>$role,
                              ":is_base"=>($req['isbase_position']==$k?'1':'0'))
                          );
          }
        }
      }
      //fall into new lookup---
    }
    $query = DataManager::Query("db.abtests.abtest:nocache",
                          "SELECT * FROM userdata.abtest ORDER BY status, role, cobrand, effective_dt",
                          array()
                         );
    $vars['last_version'] = 0;
    foreach($query->results as $res) {
      $vars['abtest'][$res->status][$res->role][$res->cobrand][$res->effective_dt][]
        = array('Version'=>$res->version,
                'Percent'=>$res->percent,
                'Duration'=>$res->duration_days,
                'Config'=>$res->config,
                'IsBase'=>$res->is_base);
      if($vars['last_version'] < $res->version) $vars['last_version'] = $res->version;
    }
    $config = ConfigManager::singleton();
    $cobrands=$config->GetCobrandList('name');
    $cobrand_test="";
    foreach($cobrands['cobrand'] as $k=>$v) {
      preg_match('#\.([^.]+)#', $v->name, $matches);
      if ($cobrand_test!=$matches[1]) $vars['cobrands'][] = $matches[1];
      $cobrand_test=$matches[1];
    }
    for ($i=0; $i<40; $i++) {
      $vars['dates'][]=date("Y-m-d", 86400*$i + time());
    }
    $content = $this->GetTemplate("./abtests.tpl", $vars);
    if ($output == "ajax") {
      $ret["tf_debug_tab_abtests"] = $content;
    } else {
      $ret = $content;
    }
    return $ret;
  }
  public function controller_ping($args) {
    return $this->GetComponentResponse("./ping.tpl");
  }
  public function controller_accessviolation($args) {
    return $this->GetComponentResponse("./accessviolation.tpl");
  }
  public function controller_404($args, $output="inline") {
    if ($output == "inline") {
      $componentname = any(ConfigManager::get("page.missing"), NULL);
    } else {
      $componentname = any(ConfigManager::get("page.404"), NULL);
    }
    if (!empty($componentname)) {
      return ComponentManager::fetch($componentname, $args, $output);
    }
    return $this->GetComponentResponse("./404.tpl", $args);
  }
  /**
   * Perform a multi-part request.  Format:
   * [
   *    {"component": <componentname>, "args": { ... }},
   *    ...
   * ]
   *
   * Supported parameters:
   *   - component : name of component to call
   *   - args      : associative array of arguments for this component.  supports basic variable subtitution.
   *   - output    : output type for this component (js,xml,ajax,json,data; defaults to "data")
   *   - target    : if using .json or .ajax output types, the id to inject this content into on the client side
   *   - assign    : make the output of this component available for variable substitution in args
   * 
   */
  public function controller_multirequest($args, $output) {
    /*
     * Example request:
     *
     * [
     *    {"component":"browse","args":{},"assign":"browse"},
     *    {"component":"utils.list","args":{"itemcomponent":"browse.node","itemclass":"tf_browse_node","items":"$browse.node.products"},"output":"snip"}
     * ]
     *
     */
    $vars = array();
    $isajax = ($output == "ajax" || $output == "json");
    if (!empty($args["requests"])) {
      $requests = (is_string($args["requests"]) ? json_decode($args["requests"], true) : $args["requests"]);
      if (is_array($requests)) {
        $cmanager = ComponentManager::singleton();

        foreach ($requests as $k=>$req) {
          if (!empty($req["component"])) {
            $componentargs = any($req["args"], array());
            // Perform variable substitution if requested.  
            // Any arg that starts with a $ is treated as a variable reference
            foreach ($componentargs as $argname=>$argval) {
              if ($argval[0] == '$') {
                $argreplace = array_get($args, substr($argval, 1));
                if (!empty($argreplace)) {
                  $componentargs[$argname] = $argreplace;
                } else {
                  $componentargs[$argname] = "";
                }
              }
            }

            $componentoutput = any($req["output"], ($isajax && !empty($req["target"]) ? "snip" : "data"));
            //$componentresponse = ComponentManager::fetch($req["component"], $componentargs, $componentoutput);
            $path = "/" . str_replace(".", "/", $req["component"]);
            $response = $cmanager->Dispatch($path, $componentargs, $componentoutput, false);
            $componentresponse = $response["content"];
            
            if (!empty($req["assign"])) {
              // assign component output back into args array
              $args[$req["assign"]] = $componentresponse;
            }
            
            if ($isajax) {
              // special handling for ajaxlib response type
              if (!empty($req["target"]) && $componentoutput != "data") {
                $vars[$req["target"]] = $componentresponse;
              } else if ($componentoutput == "snip" || $componentoutput == "html" || $componentoutput == "xhtml") {
                $varkey = any($req["target"], $req["assign"], $k);
                $vars["xhtml"][$varkey] = $componentresponse;
              } else {
                if (!$req["ignore"]) {
                  // dont include ignored responses in XHR return data
                  $varkey = any($req["target"], $req["assign"], $k);
                  
                  if ($req["expose"]) {
                    // only include in XHR response properties that match EXPOSE comma seperated string
                    $inclusion_properties = explode(",", $req["expose"]);
                    $allow = array();
                    
                    for ($i = 0; $i < count($inclusion_properties); $i++) {
                      $property = $inclusion_properties[$i];
                      $allow[$property] = $componentresponse[$property];
                    }
                    
                    $vars["data"][$varkey] = $allow;
                  } else {
                    // send everything
                    $vars["data"][$varkey] = $componentresponse;
                  }
                }
              }
            } else {
              // standard handling is to return each component response in order
              $vars["responses"][$k] = $componentresponse;
            }
          }
        }
      } else {
        // JSON parse failed
        // TODO - return some sort of error code
      }
    }
    // no default HTML template....should there be?
    return $this->GetComponentResponse(null, $vars);
  }

  /* Sharded database sync tool */
  function controller_dbsync($args) {
    ini_set('memory_limit', '1024M');

    $hosts = explode(",", any($args["hosts"], "t00,t01,t1000,t1001"));
    $basedir = "/home/james/dbsync/test";
    $width = 200;

    $vars["buckets"] = array("" => "");
    for ($i = 0; $i < 256; $i++) {
      $bucket = sprintf("%02x", $i);
      $vars["buckets"][$bucket] = $bucket;
    }

    $vars["bucket"] = any($args["bucket"], false);
    $vars["prefix"] = any($args["prefix"], "");
    if (!empty($vars["bucket"])) {
      $lists = array();
      for ($i = 0; $i < count($hosts) - 1; $i++) {
        for ($j = $i+1; $j < count($hosts); $j++) {
          $file1 = $basedir . "/" . (!empty($vars["prefix"]) ? $vars["prefix"] . "-" : "") . "dump-userlists-" . $vars["bucket"] . "-" . $hosts[$i] . ".tsv.gz";
          $file2 = $basedir . "/" . (!empty($vars["prefix"]) ? $vars["prefix"] . "-" : "") . "dump-userlists-" . $vars["bucket"] . "-" . $hosts[$j] . ".tsv.gz";
          $cmd = "zdiff -t -y --width=$width --suppress-common-lines $file1 $file2";
          
          $output = shell_exec($cmd);
          if (!empty($output)) {
            $this->processdbdiff($lists, $hosts[$i], $hosts[$j], $output, $width);
          }
        }
      }
      $output = "";
      $vars["sync"] = array();
      foreach ($lists as $k=>$v) {
        $latest = 0;
        $besthost = false;
        ksort($lists[$k]);
        foreach ($lists[$k] as $server=>$obj) {
          if ($obj && $obj[1][4] > $latest) {
            $latest = $obj[1][4];
          }
        }
        foreach ($hosts as $server) {
          $obj = $lists[$k][$server];
          $status = "missing";
          if ($obj) {
            $status = ($obj[1][4] == $latest ? "valid" : "stale");
          }
          $lists[$k][$server][0] = $status;
          if ($besthost === false && $status == "valid") {
            $besthost = $server;
          }
        }
        foreach ($hosts as $host) {
          if ($host != $besthost && $lists[$k][$host][0] != 'valid') {
            $vars["sync"][$besthost][$k][] = $host;
          }
        }
        ksort($lists[$k]);
      }
      $vars["lists"] = $lists;
    }
    return $this->GetComponentResponse("./dbsync.tpl", $vars);
  }
  function processdbdiff(&$lists, $firstserver, $secondserver, $str, $width) {
    $halfwidth = $width / 2;
    $lines = explode("\n", $str);
    $statusnames = array(
      "<" => "added",
      "|" => "changed",
      ">" => "missing"
    );
    $diff = array();
    foreach ($lines as $l) {
      $status = $statusnames[$l[$halfwidth-1]];
      $first = preg_replace("/ +/", "\t", trim(substr($l, 0, $halfwidth-1)));
      $second = preg_replace("/ +/", "\t", trim(substr($l, $halfwidth+2)));
      $firstdata = explode("\t", $first);
      $seconddata = explode("\t", $second);
      $firstname = sprintf("%s.%s.%s", $firstdata[0], $firstdata[1], $firstdata[2]);
      $secondname = sprintf("%s.%s.%s", $seconddata[0], $seconddata[1], $seconddata[2]);
      
//print_pre("'" . $first . "' !!!" . $status . "!!! '" . $second . "'");
      if (empty($first) && !empty($second)) {
        //$lists[$secondname][$firstserver] = false;
        $lists[$secondname][$secondserver] = array("added", $seconddata);
      } else if (empty($second) && !empty($first)) {
        $lists[$firstname][$firstserver] = array("added", $firstdata);
        //$lists[$firstname][$secondserver] = false;
      } else if (!empty($first) && !empty($second)) {
        $lists[$firstname][$firstserver] = array("changed", $firstdata);
        $lists[$firstname][$secondserver] = array("changed", $seconddata);
      }
    }
    ksort($lists);
    return $lists;
  }
}  
