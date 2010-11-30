{component name="html.header"}
{dependency type="component" name="demo.blog"}

<div class="blog ui_clear_after">
 <h2>{$blog->title}</h2>
 <h3>{$blog->subtitle}</h3>

 <div class="blog_posts">
  {component name="demo.blog.posts" blog=$blog}
 </div>
 <div class="blog_posts_create">
  {component name="demo.blog.create_post" blog=$blog header="Add a New Post" componentargs=$args}
	{* Comment above and uncommment below to use the Zend_Form based Elation_Form  *}
	{* component name="demo.blog.create_postZend" blog=$blog header="Add a New Post" componentargs=$args *}
 </div>
 <a class="blog_backlink" href="/blog">&laquo; back</a>
</div>

{component name="html.footer"}
