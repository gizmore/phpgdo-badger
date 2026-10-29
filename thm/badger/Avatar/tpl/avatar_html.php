<?php
namespace GDO\Avatar\tpl;

use GDO\Avatar\GDO_Avatar;
use GDO\Avatar\GDT_Avatar;

/** @var GDT_Avatar $field * */
$az = round($field->imageWidth, 1);
$px = "{$az}px";
$gender = $field->user->getGender();
$bot = $field->user->isBot() ? ' bot' : '';
$field->css('width', $px);
$field->css('height', $px);
$field->addClass("gdo-avatar $gender$bot");
$avatar = GDO_Avatar::forUser($field->user);
?>
<?php
if ($field->withLink) : ?>
<a href="<?=$field->hrefUser()?>">
	<?php
	endif; ?>
    <span<?=$field->htmlAttributes()?>
><img title="<?=t('avatar_of', [$field->user->renderUserName()]);?>"
      alt="<?=t('avatar_of', [$field->user->renderUserName()]);?>"
      src="<?=$avatar->hrefImage()?>"
      style="padding:<?=round($az / 24, 1)?>px;width:<?=$px?>;height:<?=$px?>;"/></span>
	<?php
	if ($field->withLink) : ?>
</a>
<?php
endif; ?>
