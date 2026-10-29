<?php
namespace GDO\ITMB\thm\itmp\Core\tpl\page;

use GDO\User\GDO_User;

?>
<h2><?=sitename()?></h2>
<p><?=t('itmb_impressum_info')?></p>
<?php
foreach (GDO_User::admins() as $admin)
{
	echo $admin->renderCard();
}
?>
