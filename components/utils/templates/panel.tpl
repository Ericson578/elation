{dependency type="component" name="utils.panel"}{if !empty($panel.enabled) && empty($panel.json_only)}
  <div id="{$panel.id}_inner{if $panel.uid}_{$panel.uid}{/if}" class="tf_utils_panel tf_utils_panel_{$panel.type}{if !$panel.noclear} tf_util_clear_after{/if} {$panel.cfg.classname}">
    {if !empty($panel.cfg.label) || !empty($panel.cfg.icon)}<h3 class="tf_utils_panel_label">{if !empty($panel.cfg.link)}<a href="{$panel.cfg.link}" rel="nofollow">{else}<span class="tf_utils_panel_labeltext">{/if}{if !empty($panel.cfg.icon)}<img class="tf_utils_panel_icon" src="{$panel.cfg.icon|escape:html}" alt="{$panel.cfg.label|escape:html}"/>{/if}{if !empty($panel.cfg.label)} {$panel.cfg.label}{/if}{if !empty($panel.cfg.link)}</a>{else}</span>{/if}</h3>{/if} 
    {if $panel.cfg.simple != 1}<ul {if !empty($panel.cfg.id)}id="{$panel.cfg.id}" {/if}class="tf_utils_panel_content{if !$panel.noclear} tf_util_clear_after{/if}{if !empty($panel.cfg.orientation)} tf_utils_panel_{$panel.cfg.orientation}{/if}">{/if}
    {foreach from=$panel.cfg.items key=k item=panelitem name=panelitem}
      {if $panelitem.enabled !== "0"}
        {if $panel.cfg.simple != 1}
          <li id="{$panel.id}_{$k}" title="{$panelitem.title}" class="tf_utils_panel_content_item{if $smarty.foreach.panelitem.first} tf_panel_first{elseif $smarty.foreach.panelitem.last} tf_panel_last{/if}{if $panelitem.selected == 'true'} selected{/if}{if $panelitem.classname} {$panelitem.classname}{/if}{if !empty($panelitem.icon)} tf_utils_panel_item_hasicon{/if}">{/if}
          {if $panelitem.type == "panel" || !empty($panelitem.items)}
            {component name="utils.panel" panelname=$k panel=$panelitem paneltype="panel_subpanel" panelargs=$panel.args parent=$panel.name}
          {else}
            {component name="utils.panel_item" panelitem=$panelitem panelargs=$panel.args}
          {/if}
        {if $panel.cfg.simple != 1}</li>{/if}
      {/if}
    {/foreach}
    {if $panel.cfg.simple != 1}</ul>{/if}
  </div>
  {if $panel.cfg.simple != 1 && $panel.json_include}
    <script type="text/javascript">
      elation.onloads.add(function() {ldelim}
        elation.panel.add('{$panel.name}'{if !$panel.cfg.noargs}, {jsonencode var=$panel}{/if});
      {rdelim});
    </script>
  {/if}
{elseif !empty($panel.json_only)}{jsonencode var=$panel}{/if}
{* dependency type="component" name="utils.paneledit" *}
{if !empty($panel.cfg.dependency)}
  {foreach from=$panel.cfg.dependency item=dependency}
    {dependency type=$dependency.type name=$dependency.name}
  {/foreach}
{/if}
