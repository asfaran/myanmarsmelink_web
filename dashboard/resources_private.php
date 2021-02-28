<?php
define('PRIVATE_SUBMENU', true, true);
define('PROFILE_PAGE', true, true);
define('MESSAGES_PAGE', true, true);
define('PARENT', 'MYACCOUNT', true);

define('ADD_BOOTBOX_SUPPORT', true, true);

$type = strtoupper(urldecode(filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING)));
$type_name = '';
$type_list_title = '';
$type_list_header = '';
$is_admin = current_user_can('edit_others_posts');

if (!$type)
{
    $type = BP_Node::NODE_TYPE_RESOURCE;
}
switch ($type) {
    case BP_Node::NODE_TYPE_RESOURCE:
        $type_name = 'Resource';
        $type_list_title = 'Available Resources';
        $type_list_header = 'List of resources';
        break;
    case BP_Node::NODE_TYPE_DOWNLOAD:
        $type_name = 'Download';
        $type_list_title = 'Uploaded Company Docs';
        $type_list_header = 'Company docs for download';
        break;
    case BP_Node::NODE_TYPE_POST:
        $type_name = 'Post';
        $type_list_title = 'Uploaded Posts';
        $type_list_header = 'Uploaded posts';
}

$type_encoded = urlencode(strtolower($type));
$page_link = site_url('dashboard/profile/resources') . '?type=' . $type_encoded;

$current_company_id = biz_portal_get_current_company_id();
$categories_list = biz_portal_node_get_categories();


$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_STRING);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$BP_Repo_Nodes = new BP_Repo_Nodes($wpdb, biz_portal_get_table_prefix());

if ($action === 'delete' && isset($_POST['resource_id']))
{   
    $delete_id = filter_input(INPUT_POST, 'resource_id', FILTER_VALIDATE_INT);
    $res = 0;
    if ($delete_id > 0)
        $res = $BP_Repo_Nodes->delete($delete_id);
    
    if ($res > 0) 
        BP_FlashMessage::Add("Selected item was deleted", BP_FlashMessage::SUCCESS);
    
    wp_redirect($page_link);
    exit();
}


if (isset($_POST['resource_id']))
{
    $id = filter_input(INPUT_POST, 'resource_id', FILTER_VALIDATE_INT);
    $title = filter_input(INPUT_POST, "txt_title", FILTER_SANITIZE_STRING);
    $content = filter_input(INPUT_POST, "txt_content", FILTER_SANITIZE_STRING);
    $file_id = 0;

    // File id && category only for resources
    if ($type == BP_Node::NODE_TYPE_RESOURCE) {
        $categories = filter_input(INPUT_POST, 'category', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);        
    }
    
    if ($type != BP_Node::NODE_TYPE_POST) {
        $file_id = filter_input(INPUT_POST, 'file_attached', FILTER_VALIDATE_INT);
    }
    

    $BP_Repo_Files = new BP_Repo_Files($wpdb, biz_portal_get_table_prefix());
    $BP_Repo_Nodes = new BP_Repo_Nodes($wpdb, biz_portal_get_table_prefix());

    $BP_Node = new BP_Node();
    if ($id > 0)
    {
        $results = $BP_Repo_Nodes->find_nodes($type, array('id' => $id, 'company_id' => $current_company_id), 1);
        if (isset($results[$id]))
            $BP_Node = $results[$id];
    }

    if (empty($title)) {
        BP_FlashMessage::Add("Title can not be empty", BP_FlashMessage::ERROR);
    }
    else if (empty($content)) {
        BP_FlashMessage::Add("Content can not be empty", BP_FlashMessage::ERROR);
    }
    else {
        $new = $id == 0 ? true : false;
        $BP_Node->id = $id;
        $BP_Node->title = trim($title);
        $BP_Node->body = trim($content);
        $BP_Node->node_type = $type;
        if ($is_admin)
            $BP_Node->active = 1;
        else if ($type === BP_Node::NODE_TYPE_RESOURCE)
            $BP_Node->active = 0;
        else 
            $BP_Node->active = 1;        

        if (!$BP_Node->company_id > 0) {
            $BP_Node->company_id = $current_company_id;
        }

        $BP_Node->attachments = array();
        if ($file_id > 0)
            $BP_Node->add_attachment(new BP_File($file_id));

        $BP_Node->categories = array();
        if (isset($categories) && is_array($categories)) {
            foreach ($categories as $key => $value) {
                $BP_Node->add_categories(new BP_NodeCategory($value));               
            }
        }
        
        $res = $BP_Repo_Nodes->update_node($BP_Node);
        if ($res > 0) {
            // send the email to admin for activateion
            // score will be added after activation for resoruces
            if (!$is_admin) {
                biz_portal_node_email($res, $current_company_id);
            }    
            if ($type == BP_Node::NODE_TYPE_POST) {
                biz_portal_add_score(BP_Score::SCORE_CREATING_POST, $current_company_id);                
            }        
            else if ($type == BP_Node::NODE_TYPE_DOWNLOAD) {
                biz_portal_add_score(BP_Score::SCORE_POSTING_DOWNLOADS, $current_company_id);
            }
        }
        if ($res || $res == 0) {
            if ($file_id > 0)
                $BP_Repo_Files->add_file_usage($file_id);
            
            if ($type == BP_Node::NODE_TYPE_RESOURCE)
                BP_FlashMessage::Add("Resource sent successfully", BP_FlashMessage::SUCCESS);
            else if ($type == BP_Node::NODE_TYPE_POST)
                BP_FlashMessage::Add("Post sent successfully", BP_FlashMessage::SUCCESS);
            else if ($type == BP_Node::NODE_TYPE_DOWNLOAD)
                BP_FlashMessage::Add("Document sent successfully", BP_FlashMessage::SUCCESS);
            
            wp_redirect(site_url('dashboard/profile/resources') . '?type=' . $type_encoded);
            exit();
        }
    }
}

$BP_Node = new BP_Node();

if ($action === 'edit' && $id > 0)
{
    $result = biz_portal_get_node($type, $id, $current_company_id);
    if ($result)
        $BP_Node = $result;
}


// list
$pager_total_pages = 1;
$pager_page = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
if (!$pager_page) $pager_page = 1;
$pager_per_page = 5;
$pager_offset = ($pager_page * $pager_per_page) - $pager_per_page;
if ($pager_offset < 0) $pager_offset = 0;
/** @var $ViewModel_Nodes ViewModel_Nodes */
$ViewModel_Nodes = biz_portal_node_get_list($type, 0, '', '', $current_company_id, $pager_per_page, $pager_offset);    
if ($ViewModel_Nodes->total > 0) {
    $pager_total_pages = ceil($ViewModel_Nodes->total/$pager_per_page);
}                                        
?>

<?php get_header(); ?>

<!-- END HEADER -->
<div class="clearfix"></div>
<!-- BEGIN CONTAINER -->
<div
	class="page-container  page-body profile-inner">

	<!-- BEGIN PAGE -->
	<div class="container min-hight">


		<!-- BEGIN PAGE HEADER-->
		<div class="row">
			<div class="col-md-12 margin-bottom-30">
				<!-- BEGIN PAGE TITLE & BREADCRUMB-->
				<h3 class="page-title">
					
                    <?php echo $type == BP_Node::NODE_TYPE_DOWNLOAD ? 'Corporate Documents' : 'My '.$type_name; ?>
				</h3>
				<?php if (BP_FlashMessage::HasMessages()) : ?>
				<?php biz_portal_get_messages(); ?>
				<?php endif; ?>
				<!-- END PAGE TITLE & BREADCRUMB-->
			</div>
		</div>
		<!-- END PAGE HEADER-->
		<!-- BEGIN PAGE CONTENT-->
		<div class="row profile">
			<div class="col-md-12">
				<!--BEGIN TABS-->
				<div class="tabbable tabbable-custom tabbable-full-width">
					<ul class="nav nav-tabs">
						<li><a href="/dashboard/profile">Overview</a></li>
						<li><a href="/dashboard/profile/accounts">Account</a></li>
						<li
							class="<?php echo $type == BP_Node::NODE_TYPE_RESOURCE ? 'active' : ''; ?>">
							<a
							href="/dashboard/profile/resources?type=<?php echo urlencode(strtolower(BP_Node::NODE_TYPE_RESOURCE)) ?>">Resources</a>
						</li>
						<li
							class="<?php echo $type == BP_Node::NODE_TYPE_POST ? 'active' : ''; ?>">
							<a
							href="/dashboard/profile/resources?type=<?php echo urlencode(strtolower(BP_Node::NODE_TYPE_POST)) ?>">Posts</a>
						</li>
						<li
							class="<?php echo $type == BP_Node::NODE_TYPE_DOWNLOAD ? 'active' : ''; ?>">
							<a
							href="/dashboard/profile/resources?type=<?php echo urlencode(strtolower(BP_Node::NODE_TYPE_DOWNLOAD)) ?>">Corporate Documents</a>
						</li>

					</ul>
					<div class="tab-content">

						<!--end tab-pane-->
						<div class="tab-pane active" id="tab_1_4">
							<div class="row">
								<div class="col-md-3">
								    <?php 
								    $new_tab_url = '#tab_2-2';
								    $new_tab_data_toggle = 'data-toggle="tab"';
								    if ($action === 'edit') {
								        $new_tab_url = site_url('dashboard/profile/resources') . '?type=' . $type . '&action=new';
								        $new_tab_data_toggle = '';
								    }
								    ?>
									<ul class="ver-inline-menu tabbable margin-bottom-10">
										<li
											class="<?php echo ($action != 'new' && $action != 'edit') ? 'active' : ''; ?>"><a
											data-toggle="tab" href="#tab_1-1"> <i class="fa fa-file-text"></i>
												<?php echo $type_list_title; ?>
										</a> <span class="after"></span></li>
										<li
											class="<?php echo ($action == 'new' || $action == 'edit') ? 'active' : ''; ?>"><a
											<?php echo $new_tab_data_toggle; ?> href="<?php echo $new_tab_url; ?>"><i
												class="fa fa-plus-square"></i> <?php echo $action == 'edit' ? 'Edit' : 'New'; ?> <?php echo $type_name; ?>
										</a></li>

									</ul>
								</div>

								<div class="col-md-9">
									<div class="tab-content">


										<div id="tab_1-1"
											class="tab-pane <?php echo ($action != 'new' && $action != 'edit') ? 'active' : ''; ?>">

											<div class="row">
												<div class="col-md-12">
													<div class="add-portfolio">
														<span><?php echo $type_list_header; ?>
														</span>

													</div>
												</div>
											</div>
											<!--end add-portfolio-->
											
											<?php
											$title_col_width = 'col-md-10';
											if ($type != BP_Node::NODE_TYPE_POST ) {
											    $title_col_width = 'col-md-8';
											} 
											?>
											<?php /** @var BP_Node $BP_Node */ ?>
											<?php foreach ($ViewModel_Nodes->nodes as $node) : ?>
											<?php
											$line=$body;
											if (preg_match('/^.{1,50}\b/s', $node->body, $match))
											{
											    $line=$match[0];
											} 
											$edit_link = $page_link . '&action=edit&id=' . $node->id;																					
											?>
											<div class="row portfolio-block">
											    <?php if ($type != BP_Node::NODE_TYPE_POST) {
												$attachment_id = 0;
												$attachment_link = "#";
												$thumb_url = '';
												$download_disabled = "disabled='disabled'";												
												foreach ($node->attachments as $key => $value) {
												    if ($key > 0) {
												        $attachment_link = biz_portal_get_file_url($key, 0, 1);
												        $download_disabled = '';
												        if ($value->is_image == 1)  {
												            $thumb_url = biz_portal_get_file_url($key, 1, 0);
												        }
												        break;
												    }
												}} ?>
												<div class="<?php echo $title_col_width; ?>">
													<div class="portfolio-text">
													    <?php if (!empty($thumb_url)) : ?>
														<img 
															src="<?php echo $thumb_url; ?>" alt="" />
													    <?php endif;?>
														<div class="portfolio-text-info">
															<h4><?php echo $node->title; ?></h4>
															<p><?php echo '<span class="label label-default">' . date('d-m-Y', strtotime($node->created)) . '</span>' . ' ' . $line; ?> ...</p>
														</div>
														<?php if (!$node->active) : ?>
														    <span class="label label-warning"><i class="fa fa-question"></i> pending..</span>
														<?php endif; ?>
													</div>
												</div>
												<?php if ($type != BP_Node::NODE_TYPE_POST) :?>												
												<div class="col-md-2">
													<div class="portfolio-btn">
														<a <?php echo $download_disabled; ?> href="<?php echo $attachment_link; ?>" class="btn bigicn-only"><span>Download</span>
														</a>
													</div>												
												</div>
												<?php endif; ?>

												<div class="col-md-2">
													<div class="portfolio-btn">
														<a href="<?php echo $edit_link; ?>" class="btn bigicn-only"><span>Edit</span> </a>
													</div>

												</div>
											</div>
											<!--end row-->
											<?php endforeach; ?>
											<div class="row">
											    <div class="col-md-12">
											        <?php if ($pager_total_pages > 1) : ?>											        
											            <ul class="pagination pagination-centered">
											            <?php $link_prev = ($pager_page > 1) ? ($page_link . '&p=' . ($pager_page-1)) : ''; ?>
											            <?php $link_next = ($pager_page < $pager_total_pages) ? ($page_link . '&p=' . ($pager_page+1)) : ''; ?>
											            <li><a href="<?php echo $link_prev; ?>" title="Previous Page">Prev</a>
											            <?php for ($i=0; $i<$pager_total_pages; $i++) :?>											            
											            <?php $link = $page_link . '&p=' . ($i+1);  ?>          
											                <li <?php echo ($pager_page == ($i+1)) ? "class='active'":""; ?>><a href="<?php echo $link; ?>" title="<?php echo "Page $i"; ?>"><?php echo ($i+1) ?></a></li>
											            <?php endfor; ?>
											            <li><a href="<?php echo $link_next; ?>" title="Next Page">Next</a>
											            </ul>
											            <div class="pull-right">
															<?php echo "Displaying " . $ViewModel_Nodes->offset . " to " . 
											                	(count($ViewModel_Nodes->nodes) + $ViewModel_Nodes->offset) . 
											                	" of total " . $ViewModel_Nodes->total; ?>
                                                        </div>    
											        <?php endif; ?>
											    </div>
											</div>											
											
										</div>

										<div id="tab_2-2"
											class="tab-pane <?php echo ($action === 'new' || $action === 'edit') ? 'active' : ''; ?>">
											<div class="row">
												<div class="col-md-12">
													<div class="add-portfolio">

														<span><?php echo $action == 'edit' ? 'Edit' : 'New'; ?> <?php echo $type_name ?></span>

													</div>
												</div>
											</div>

											<div class="row">
												<div class="col-md-12">
													<?php
													$action_url = '/dashboard/profile/resources?type=' . $type_encoded;
													if ($action === 'edit' && $id > 0) {
													    $action_url .= '&action=edit&id=' . $id;
													}
													else {
													    $action_url .= '&action=new';
													}
													?>
													<?php if ($action === 'edit' && $BP_Node->active == 1 && !$is_admin && $type == BP_Node::NODE_TYPE_RESOURCE) :?>
													<div class="note note-warning">
                        								<h4 class="block">Warning!</h4>
                        								<p>
                        									If you want to make any udpate on this content, 
                        									it will be put back in the approval queue.
                        								</p>
                        							</div>
                        							<?php endif; ?>
													<form class="fileupload"
														action="<?php echo $action_url; ?>" method="POST"
														enctype="multipart/form-data">
														<input type="hidden" name="field_name"
															value="file_attached" />
														<div class="form-group">
															<input type="hidden" name="resource_id" id="resource_id"
																value="<?php echo !empty($BP_Node->id) ? $BP_Node->id : 0; ?>" />
															<label class="control-label">Title</label> <input
																type="text" placeholder="Title" name="txt_title"
																id="txt_title" class="form-control"
																value="<?php echo !empty($BP_Node->title) ? $BP_Node->title : ''; ?>" />
														</div>
														<div class="form-group">
															<label class="control-label">Content</label>
															<textarea name="txt_content" id="txt_content"
																class="form-control" rows="8"
																placeholder="content"><?php 
																echo !empty($BP_Node->body) ? $BP_Node->body : '';
																?></textarea>
														</div>
														<?php if ($type == BP_Node::NODE_TYPE_RESOURCE) : ?>
														<div class="form-group">
															<h4>Category</h4>

															<?php
															    $categories_selected = array();
															    if (count($BP_Node->categories) > 0) {
															        $categories_selected = array_keys($BP_Node->categories);															        
															    }		
															?>
															<?php foreach($categories_list as $cat) : ?>	
															<?php $checked = in_array($cat['id'], $categories_selected) ? 'checked="checked"' : ''; ?>														
															<div class="col-md-4 col-sm-4">
															<label
																	class="control-label">
																<input type='checkbox' name='category[]' id='category'
																	value='<?php echo $cat['id']; ?>' <?php echo $checked; ?>/> 
																	<?php echo $cat['category_name']; ?>
																</label>
															</div>
															<?php endforeach;  ?>
															<br />&nbsp;<br />
														</div>
														<?php endif; ?>


														<?php
														$BP_File = new BP_File();
														$attachment_url = '';
														if (is_array($BP_Node->attachments)) {
														    $attachment_keys = array_keys($BP_Node->attachments);
														    $BP_File = $BP_Node->attachments[$attachment_keys[0]];
														    if ($BP_File->id > 0 && $BP_File->is_image)
														        $attachment_url = biz_portal_get_file_url($BP_File->id, 1);
														    else if ($BP_File->id > 0)
														        $attachment_url = biz_portal_get_file_url($BP_File->id, 0, 1);
														}
														?>
														<div class="form-group">
															<input type="hidden" name="file_attached"
																id="file_attached" value="<?php echo $BP_File->id; ?>" />

														</div>

														<?php // HIDE FILE ATTACHMENT TAB FOR POSTS
														// ========================================
														?>
														<?php if ($type != BP_Node::NODE_TYPE_POST) : ?>

														<div class="form-group">
															<?php if ($BP_File->id > 0 && $BP_File->is_image) : ?>
															<a class="attachment_in_page" target="_blank"
																href="<?php echo biz_portal_get_file_url($BP_File->id) ?>"><img
																src="<?php echo $attachment_url; ?>" /> </a>
															<?php elseif (!empty($attachment_url)) : ?>
															Current Attachment : <a class="attachment_in_page"
																href="<?php echo $attachment_url;  ?>">Download</a>
															<?php endif; ?>
															
															<?php if ($attachment_url) :?>
															    <button id="btn_remove_attachment" class="btn btn-default" onClick="__remove_attachment();return false;">Remove</button>
															<?php endif; ?>
														</div>
														<!-- file upload -->
														
														<div>														
														    <?php if ($node->created) echo '<span class="label label-default">Created : ' . 
														        date('d-m-Y', strtotime($node->created)) . '</span>' ?><br />&nbsp;
														</div>


														<!-- The fileupload-buttonbar contains buttons to add/delete files and start/cancel the upload -->
														<div class="row fileupload-buttonbar">
															<div class="col-lg-7">
																<!-- The fileinput-button span is used to style the file input field as button -->
																<span class="btn green fileinput-button"> <i
																	class="fa fa-plus"></i> <span><?php echo $action == 'edit' ? 'Replace file' : 'Add file'; ?> ..</span> <input
																	type="file" name="files">
																</span>
																<!-- button type="reset" class="btn yellow cancel">
																	<i class="fa fa-ban"></i> <span>Cancel upload</span>
																</button>
																<input type="checkbox" class="toggle" -->
																<!-- The loading indicator is shown during file processing -->
																<span class="fileupload-loading"></span>
															</div>
														</div>
														<?php endif; ?>
														<!-- The table listing the files available for upload/download -->
														<table role="presentation"
															class="table table-striped clearfix">
															<tbody class="files"></tbody>
														</table>														
														<div class="margiv-top-10">														
														<?php if ($node->id > 0) :?>
														    <a href="#" rel="<?php echo $page_link . '&action=delete'; ?>" onClick="__delete(this);return false;" class="btn red pull-right" title="Delete">Delete</a>
														<?php endif; ?>
															<input type="submit" name="btn_submit" id="btn_submit"
																class="btn green" value="Send" /> <a href="<?php echo $page_link; ?>"
																class="btn default">Cancel</a>
															<input class="btn default pull-right" type="reset" value="Reset" />
														</div>
													</form>
													<!-- file upload end -->
												</div>
											</div>

										</div>
										<!--end tab_2-2 -->

									</div>
								</div>

							</div>
							<!--end row-->
						</div>
						<!--end tab-pane-->

						<!--end tab-pane-->
					</div>
				</div>
				<!--END TABS-->
			</div>
		</div>
		<!-- END PAGE CONTENT-->
	</div>
	<!-- END PAGE -->
</div>
<!-- END CONTAINER -->
<?php if ($type != BP_Node::NODE_TYPE_POST) : ?>
<script id="template-upload" type="text/x-tmpl">
		{% for (var i=0, file; file=o.files[i]; i++) { %}
		    <tr class="template-upload fade">
		        <td>
		            <span class="preview"></span>
		        </td>
		        <td>
		            <p class="name">{%=file.name%}</p>
		            {% if (file.error) { %}
                        <div><span class="label label-warning">Attention</span> {%=file.error%}</div>
		            {% } %}
		        </td>
		        <td>
		            <p class="size">{%=o.formatFileSize(file.size)%}</p>
		            {% if (!o.files.error) { %}
		                <div class="progress progress-striped active" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
		                <div class="progress-bar progress-bar-success" style="width:0%;"></div>
		                </div>
		            {% } %}
		        </td>
		        <td>
		            {% if (!o.files.error && !i && !o.options.autoUpload) { %}
		                <button class="btn blue start">
		                    <i class="fa fa-upload"></i>
		                    <span>Start</span>
		                </button>
		            {% } %}
		            {% if (!i) { %}
		                <button class="btn red cancel">
		                    <i class="fa fa-ban"></i>
		                    <span>Cancel</span>
		                </button>
		            {% } %}
		        </td>
		    </tr>
		{% } %}
	</script>
<!-- The template to display files available for download -->
<script id="template-download" type="text/x-tmpl">
		{% for (var i=0, file; file=o.files[i]; i++) { %}
		    <tr class="template-download fade">
		        <td>
		            <span class="preview">
		                {% if (file.thumbnailUrl) { %}
		                    <a href="{%=file.url%}" title="{%=file.name%}" download="{%=file.name%}" data-gallery><img src="{%=file.thumbnailUrl%}"></a>
		                {% } %}
		            </span>
		        </td>
		        <td>
		            <p class="name">
		                {% if (file.url) { %}
		                    <a href="{%=file.url%}" title="{%=file.name%}" download="{%=file.name%}" {%=file.thumbnailUrl?'data-gallery':''%}>{%=file.name%}</a>
		                {% } else { %}
		                    <span>{%=file.name%}</span>
		                {% } %}
		            </p>
		            {% if (file.error) { %}
		                <div><span class="label label-warning">Attention</span> {%=file.error%}</div>
		            {% } %}
		        </td>
		        <td>
		            <span class="size">{%=o.formatFileSize(file.size)%}</span>
		        </td>
                <td>
		            {% if (file.deleteUrl) { %}
		                <button class="btn red delete" data-type="{%=file.deleteType%}" data-url="{%=file.deleteUrl%}"{% if (file.deleteWithCredentials) { %} data-xhr-fields='{"withCredentials":true}'{% } %}>
		                    <i class="fa fa-trash-o"></i>
		                    <span>Delete</span>
		                </button>
		                <!-- input type="checkbox" name="delete" value="1" class="toggle" -->
		            {% } else { %}
		                <button class="btn yellow cancel">
		                    <i class="fa fa-ban"></i>
		                    <span>Cancel</span>
		                </button>
		            {% } %}
		        </td>		        
		    </tr>
		{% } %}
	</script>
<?php endif; ?>
<script>
function __remove_attachment()
{
	jQuery(".attachment_in_page").remove();
	jQuery("#file_attached").val(0);
}

function __delete(obj)
{
	bootbox.confirm("Are you sure you want to delete this item?", function(result) {
		if (result == true) {
			var del_link = $(obj).attr('rel');
			var form = jQuery(obj).closest('form')
			jQuery(form).attr('action', del_link);
			jQuery(form).submit();
		}
	});
}
</script>
<?php get_footer(); ?>