<#5432>
<?php
$template = 'il_lso_admin';
$perms = [
	'create_htlm',
	'create_iass',
	'create_copa',
	'create_svy',
	'create_svy',
	'create_lm',
	'create_exc',
	'create_tst',
	'create_sahs',
	'create_file',
	'participate',
	'unparticipate',
	'edit_learning_progress',
	'manage_members',
	'copy'
];

$query = "SELECT obj_id FROM object_data"
	." WHERE object_data.type = " .$ilDB->quote('rolt', 'text')
	." AND title = " .$ilDB->quote($template,'text');
$result = $ilDB->query($query);
$rol_id = array_shift($ilDB->fetchAssoc($result));

$op_ids = [];
$query = "SELECT ops_id FROM rbac_operations"
	." WHERE operation IN ('"
	.implode("', '", $perms)
	."')";
$result = $ilDB->query($query);
while($row = $ilDB->fetchAssoc($result)) {
	$op_ids[] = $row['ops_id'];
}

include_once('./Services/Migration/DBUpdate_3560/classes/class.ilDBUpdateNewObjectType.php');
ilDBUpdateNewObjectType::setRolePermission($rol_id, 'lso', $op_ids,	ROLE_FOLDER_ID);
?>

<#5433>
<?php
include_once('./Services/Migration/DBUpdate_3560/classes/class.ilDBUpdateNewObjectType.php');
$template = 'il_lso_member';
$op_id = ilDBUpdateNewObjectType::getCustomRBACOperationId('unparticipate');

$query = "SELECT obj_id FROM object_data"
	." WHERE object_data.type = " .$ilDB->quote('rolt', 'text')
	." AND title = " .$ilDB->quote($template,'text');
$result = $ilDB->query($query);
$rol_id = array_shift($ilDB->fetchAssoc($result));

ilDBUpdateNewObjectType::setRolePermission($rol_id, 'lso', [$op_id], ROLE_FOLDER_ID);
?>
<#5434>
<?php
if ($ilDB->tableExists('license_data')) {
	$ilDB->dropTable('license_data');
}
?>
<#5435>
<?php
$ilDB->manipulateF(
	'DELETE FROM settings WHERE module = %s',
	['text'],
	['license']
);
?>
<#5436>
<?php
$ilCtrlStructureReader->getStructure();
?>
<#5437>
<?php
$ilCtrlStructureReader->getStructure();
?>
<#5438>
<?php
require_once 'Services/Migration/DBUpdate_3560/classes/class.ilDBUpdateNewObjectType.php';
ilDBUpdateNewObjectType::applyInitialPermissionGuideline('iass', true, false);
?>
<#5439>
<?php
$ilCtrlStructureReader->getStructure();
?>
<#5440>
<?php
$ilCtrlStructureReader->getStructure();
?>
<#5441>
<?php
// Create migration table
if (!$ilDB->tableExists('frm_thread_tree_mig')) {
	$fields = [
		'thread_id' => [
			'type'    => 'integer',
			'length'  => 4,
			'notnull' => true,
			'default' => 0
		]
	];

	$ilDB->createTable('frm_thread_tree_mig', $fields);
	$ilDB->addPrimaryKey('frm_thread_tree_mig', ['thread_id']);
	$GLOBALS['ilLog']->info(sprintf(
		'Created thread migration table: frm_thread_tree_mig'
	));
}
?>
<#5442>
<?php
$query = "
	SELECT frmpt.thr_fk
	FROM frm_posts_tree frmpt
	INNER JOIN frm_posts fp ON fp.pos_pk = frmpt.pos_fk
	WHERE frmpt.parent_pos = 0
	GROUP BY frmpt.thr_fk
	HAVING COUNT(frmpt.fpt_pk) > 1
";
$ignoredThreadIds = [];
$res = $ilDB->query($query);
while ($row = $ilDB->fetchAssoc($res)) {
	$ignoredThreadIds[$row['thr_fk']] = $row['thr_fk'];
}

$query = "
	SELECT fp.*, fpt.fpt_pk, fpt.thr_fk, fpt.lft, fpt.rgt, fpt.fpt_date
	FROM frm_posts_tree fpt
	INNER JOIN frm_posts fp ON fp.pos_pk = fpt.pos_fk
	LEFT JOIN frm_thread_tree_mig ON frm_thread_tree_mig.thread_id = fpt.thr_fk
	WHERE fpt.parent_pos = 0 AND fpt.depth = 1 AND frm_thread_tree_mig.thread_id IS NULL
";
$res = $ilDB->query($query);
while ($row = $ilDB->fetchAssoc($res)) {
	$GLOBALS['ilLog']->info(sprintf(
		"Started migration of thread with id %s", $row['thr_fk']
	));
	if (isset($ignoredThreadIds[$row['thr_fk']])) {
		$GLOBALS['ilLog']->warning(sprintf(
			"Cannot migrate forum tree for thread id %s in database update step %s", $row['thr_fk'], $nr
		));
		continue;
	}

	// Create space for a new root node, increment depth of all nodes, increment lft and rgt values
	$ilDB->manipulateF("
			UPDATE frm_posts_tree
			SET
				lft = lft + 1,
				rgt = rgt + 1,
				depth = depth + 1
			WHERE thr_fk = %s
		",
		['integer'],
		[$row['thr_fk']]
	);
	$GLOBALS['ilLog']->info(sprintf(
		"Created gaps in tree for thread with id %s in database update step %s", $row['thr_fk'], $nr
	));

	// Create a posting as new root
	$postId = $ilDB->nextId('frm_posts');
	$ilDB->insert('frm_posts', array(
		'pos_pk'		=> array('integer', $postId),
		'pos_top_fk'	=> array('integer', $row['pos_top_fk']),
		'pos_thr_fk'	=> array('integer', $row['pos_thr_fk']),
		'pos_display_user_id'	=> array('integer', $row['pos_display_user_id']),
		'pos_usr_alias'	=> array('text', $row['pos_usr_alias']),
		'pos_subject'	=> array('text', $row['pos_subject']),
		'pos_message'	=> array('clob', $row['pos_message']),
		'pos_date'		=> array('timestamp', $row['pos_date']),
		'pos_update'	=> array('timestamp', null),
		'update_user'	=> array('integer', 0),
		'pos_cens'		=> array('integer', 0),
		'notify'		=> array('integer', 0),
		'import_name'	=> array('text', (string)$row['import_name']),
		'pos_status'	=> array('integer', 1),
		'pos_author_id' => array('integer', (int)$row['pos_author_id']),
		'is_author_moderator' => array('integer', $row['is_author_moderator']),
		'pos_activation_date' => array('timestamp', $row['pos_activation_date'])
	));
	$GLOBALS['ilLog']->info(sprintf(
		"Created new root posting with id %s in thread with id %s in database update step %s",
		$postId, $row['thr_fk'], $nr
	));

	// Insert the new root and, set dept = 1, lft = 1, rgt = <OLR_ROOT_RGT> + 2
	$nextId = $ilDB->nextId('frm_posts_tree');
	$ilDB->manipulateF('
		INSERT INTO frm_posts_tree
		( 
			fpt_pk,
			thr_fk,
			pos_fk,
			parent_pos,
			lft,
			rgt,
			depth,
			fpt_date
		) VALUES (%s, %s, %s, %s,  %s,  %s, %s, %s)',
		['integer','integer', 'integer', 'integer', 'integer', 'integer', 'integer', 'timestamp'],
		[$nextId, $row['thr_fk'], $postId, 0, 1, $row['rgt'] + 2, 1, $row['fpt_date']]
	);
	$GLOBALS['ilLog']->info(sprintf(
		"Created new tree root with id %s in thread with id %s in database update step %s",
		$nextId, $row['thr_fk'], $nr
	));

	// Set parent_pos for old root
	$ilDB->manipulateF("
			UPDATE frm_posts_tree
			SET
				parent_pos = %s
			WHERE thr_fk = %s AND fpt_pk = %s
		",
		['integer', 'integer'],
		[$nextId, $row['fpt_pk']]
	);
	$GLOBALS['ilLog']->info(sprintf(
		"Set parent to %s for posting with id %s in thread with id %s in database update step %s",
		$nextId, $row['fpt_pk'], $row['thr_fk'], $nr
	));

	// Mark as migrated
	$ilDB->insert('frm_thread_tree_mig', array(
		'thread_id' => array('integer', $row['thr_fk'])
	));
}
?>
<#5443>
<?php
// Drop migration table
if ($ilDB->tableExists('frm_thread_tree_mig')) {
	$ilDB->dropTable('frm_thread_tree_mig');
	$GLOBALS['ilLog']->info(sprintf(
		'Dropped thread migration table: frm_thread_tree_mig'
	));
}
?>
<#5444>
<?php
// Add new index
if (!$ilDB->indexExistsByFields('frm_posts_tree', ['parent_pos'])) {
	$ilDB->addIndex('frm_posts_tree', ['parent_pos'], 'i3');
}
?>