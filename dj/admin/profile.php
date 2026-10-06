<?php

declare(strict_types=1);

use TildeRadio\Site\Admin\Input;
use TildeRadio\Site\Admin\Problem;
use TildeRadio\Site\Admin\ProfileForm;
use TildeRadio\Site\Admin\ProfileValidator;

require __DIR__ . '/_admin.php';
$editing = $selfService || isset($_GET['slug']);
try {
    $slug = $selfService ? ($djAccount['profile_slug'] ?? throw new Problem('Ask an administrator to link your profile first.', 403)) : ($editing ? Input::slug($_GET['slug']) : '');
    $djStore->requireProfileAccess($adminIdentity, $slug);
    $profile = $editing ? ($djStore->profile($slug) ?? throw new Problem('Profile not found.', 404)) : null;
} catch (Problem $problem) {
    tr_dj_error($problem->status, $problem->getMessage());
}
$error = null;
$data = $profile['data'] ?? ['name' => '', 'published' => true];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $error = tr_admin_attempt(function () use ($editing, $slug, $profile, $djStore, $adminIdentity, $selfService, &$data): void {
        $action = Input::text($_POST['action'] ?? '', 'action', 30, true);
        $target = $editing ? $slug : Input::slug($_POST['slug'] ?? null);
        $version = Input::integer($_POST['version'] ?? '0', 'record version', true);
        if ($action === 'delete' && $editing && !$selfService) {
            $djStore->deleteProfile($adminIdentity, $target, $version, Input::text($_POST['confirm'] ?? '', 'confirmation', 80, true));
            tr_admin_finish('profiles.php', 'Public profile deleted. Its archive and streaming schedule remain intact.');
        }
        if ($action === 'save_json') {
            $data = ProfileValidator::decode(Input::text($_POST['profile_json'] ?? '', 'profile JSON', ProfileValidator::MAX_BYTES, true));
        } elseif ($action === 'save_fields') {
            $data = ProfileForm::apply($profile['data'] ?? [], $_POST);
        } else {
            throw new Problem('Choose a valid profile action.');
        }
        $djStore->saveProfile($adminIdentity, $target, $data, $version);
        tr_admin_finish('profile.php?slug=' . rawurlencode($target), 'DJ profile saved.');
    });
}
$fieldsPosted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fields';
$value = static fn (string $key, mixed $default = '', string $separator = "\n"): string => $fieldsPosted && is_string($_POST[$key] ?? null) ? $_POST[$key] : ProfileForm::text($default, $separator);
$show = is_array($data['show'] ?? null) ? $data['show'] : [];
$favorites = is_array($data['favorites'] ?? null) ? $data['favorites'] : [];
$links = $fieldsPosted && is_array($_POST['links'] ?? null) ? $_POST['links'] : ProfileForm::links($data);
$query = $editing ? '?slug=' . rawurlencode($slug) : '';
$published = $fieldsPosted ? isset($_POST['published']) : ($profile !== null ? $profile['published'] : ($data['published'] ?? true));
tr_admin_begin($editing ? 'Edit DJ profile' : 'Create a DJ profile', 'profiles', $error);
?>
<?php if ($profile['deleted'] ?? false) : ?><p class="dj-admin-notice">This profile is deleted. Save it to restore it; choose Published if it should be public again.</p><?php endif; ?>
<form method="post" action="<?= tr_dj_h(tr_admin_url('profile.php' . $query)) ?>" class="dj-admin-form" data-tr-dj-auth>
    <?php tr_admin_csrf(); ?><input type="hidden" name="action" value="save_fields"><input type="hidden" name="version" value="<?= tr_admin_version($profile['version'] ?? 0) ?>">
    <fieldset><legend>Profile identity</legend>
        <?php if ($editing) : ?><p>Permanent URL slug: <strong><?= tr_dj_h($slug) ?></strong>. It stays fixed so existing profile and archive links continue working.</p><?php else : tr_admin_field('slug', 'Permanent profile slug', $value('slug'), 'text', true); ?><p>Use the DJ’s existing schedule/archive slug where possible, such as cat or deepend.</p><?php endif; ?>
        <?php tr_admin_field('name', 'Public display name', $value('name', $data['name'] ?? ''), 'text', true); ?>
        <label class="dj-admin-check dj-admin-check-inline"><input type="checkbox" name="published" value="1"<?= $published ? ' checked' : '' ?>>Publish this DJ profile</label>
        <div class="dj-admin-control-wide"><?php tr_admin_field('tagline', 'Tagline', $value('tagline', $data['tagline'] ?? '')); ?></div>
        <?php tr_admin_area('bio', 'Biography (blank lines between paragraphs)', $value('bio', $data['bio'] ?? '', "\n\n"), 7); ?>
        <?php tr_admin_area('description', 'Profile description', $value('description', $data['description'] ?? '')); ?>
        <?php foreach (['avatar' => 'Avatar URL or site-relative image path', 'pronouns' => 'Pronouns', 'location' => 'General location', 'tilde' => 'Tilde / community', 'irc' => 'IRC nickname', 'since' => 'Broadcasting since'] as $key => $label) : tr_admin_field($key, $label, $value($key, $data[$key] ?? '')); endforeach; ?>
    </fieldset>
    <fieldset><legend>Recurring show</legend>
        <?php tr_admin_field('show_title', 'Show title', $value('show_title', $show['title'] ?? '')); ?>
        <?php tr_admin_field('show_tagline', 'Show tagline', $value('show_tagline', $show['tagline'] ?? '')); ?>
        <?php tr_admin_area('show_description', 'Show description', $value('show_description', $show['description'] ?? '')); ?>
        <?php tr_admin_area('show_genres', 'Genres (one per line)', $value('show_genres', $show['genres'] ?? []), 3); ?>
        <?php tr_admin_field('show_timezone', 'Show format timezone', $value('show_timezone', $show['timezone'] ?? 'UTC'), 'text', true); ?>
        <p>This timezone selects weekday-specific show formats. Broadcast schedule times use the AzuraCast station timezone.</p>
        <?php tr_admin_area('show_formats', 'Weekday show formats (JSON)', $value('show_formats', json_encode($show['formats'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 8); ?>
        <p>Each format has an id, weekday names in days, and optional title, tagline, description and genres. Existing formats are preserved unless edited here.</p>
    </fieldset>
    <fieldset><legend>Links</legend><p>Leave both boxes empty to remove a link. Up to 30 links are supported.</p>
        <?php for ($index = 0, $count = min(30, count($links) + 3); $index < $count; ++$index) : ?>
            <?php $link = is_array($links[$index] ?? null) ? $links[$index] : []; ?>
            <div class="dj-admin-link-row">
                <div class="dj-admin-control"><label for="link-label-<?= $index ?>">Link <?= $index + 1 ?> label</label><input id="link-label-<?= $index ?>" name="links[<?= $index ?>][label]" value="<?= tr_dj_h(ProfileForm::text($link['label'] ?? '')) ?>" maxlength="100"></div>
                <div class="dj-admin-control"><label for="link-url-<?= $index ?>">Link <?= $index + 1 ?> URL</label><input id="link-url-<?= $index ?>" name="links[<?= $index ?>][url]" type="url" value="<?= tr_dj_h(ProfileForm::text($link['url'] ?? '')) ?>" maxlength="2048"></div>
            </div>
        <?php endfor; ?>
    </fieldset>
    <fieldset><legend>Favorites and listener notes</legend>
        <?php foreach (['artists' => 'Favorite artists', 'albums' => 'Favorite albums', 'tracks' => 'Favorite tracks'] as $key => $label) : tr_admin_area('favorites_' . $key, $label . ' (one per line)', $value('favorites_' . $key, $favorites[$key] ?? []), 4); endforeach; ?>
        <?php tr_admin_area('notes', 'Listener notes (one per line)', $value('notes', $data['notes'] ?? []), 4); ?>
    </fieldset>
    <div class="dj-admin-actions"><button type="submit" class="dj-auth-button"><?= ($profile['deleted'] ?? false) ? 'Restore profile' : 'Save profile' ?></button><a href="<?= tr_dj_h(tr_admin_url('profiles.php')) ?>">Cancel</a></div>
</form>
<details><summary>Advanced: edit the full profile JSON</summary><p>This editor supports all profile metadata. It replaces this profile’s metadata after validation. Do not include passwords or API credentials.</p>
    <form method="post" action="<?= tr_dj_h(tr_admin_url('profile.php' . $query)) ?>" class="dj-admin-form" data-tr-dj-auth>
        <?php tr_admin_csrf(); ?><input type="hidden" name="action" value="save_json"><input type="hidden" name="version" value="<?= tr_admin_version($profile['version'] ?? 0) ?>">
        <?php if (!$editing) : tr_admin_field('slug', 'Permanent profile slug', $value('slug'), 'text', true, '-json'); endif; ?>
        <?php tr_admin_area('profile_json', 'Profile JSON', ($_POST['action'] ?? '') === 'save_json' && is_string($_POST['profile_json'] ?? null) ? $_POST['profile_json'] : json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 22); ?>
        <button type="submit" class="dj-auth-button">Save profile JSON</button>
    </form>
</details>
<?php if ($editing && !$profile['deleted'] && !$selfService) : ?>
    <div class="dj-admin-danger"><h2>Delete public profile</h2><p>This hides its public page and prevents the live schedule or a deployed JSON file from recreating it. Streaming accounts, scheduled broadcasts and archives are retained.</p>
        <form method="post" action="<?= tr_dj_h(tr_admin_url('profile.php' . $query)) ?>" class="dj-admin-form" data-tr-dj-auth>
            <?php tr_admin_csrf(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="version" value="<?= tr_admin_version($profile['version']) ?>"><?php tr_admin_field('confirm', 'Type ' . $slug . ' to confirm', '', 'text', true); ?><button type="submit" class="dj-auth-button">Delete public profile</button>
        </form>
    </div>
<?php endif; ?>
<?php tr_admin_end(); ?>
