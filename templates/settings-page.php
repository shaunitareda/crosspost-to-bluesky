<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap">
<h1>Crosspost to Bluesky</h1>
<nav class="nav-tab-wrapper" style="margin-bottom:0">
    <a href="?page=crosspost-to-bluesky&tab=settings"
       class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
    <a href="?page=crosspost-to-bluesky&tab=debug"
       class="nav-tab <?php echo $active_tab === 'debug' ? 'nav-tab-active' : ''; ?>">
        Debug Log
        <?php
        $err_count = count( array_filter( $log, fn( $e ) => $e['level'] === 'ERROR' ) );
        if ( $err_count ) : ?>
            <span style="background:#b32d2e;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px"><?php echo $err_count; ?></span>
        <?php endif; ?>
    </a>
</nav>

<div style="background:#fff;border:1px solid #ccd0d4;border-top:none;padding:20px 24px">

<?php if ( $active_tab === 'settings' ) : ?>

<p>Posts and Social Notes (sent via Tusky / Enable Mastodon Apps) are crossposted to Bluesky with native image <em>and</em> video uploads. When a post contains a video, the video is sent to Bluesky instead of images (Bluesky supports one or the other per post).</p>

<p>
    <button type="button" id="ctb-test-btn" class="button">Test Connection</button>
    <span id="ctb-test-result" style="margin-left:8px;font-weight:600"></span>
</p>

<form method="post" action="options.php">
<?php settings_fields( 'ctb_settings_group' ); ?>

<h2>Bluesky Account</h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row"><label for="ctb_id">Identifier</label></th>
        <td>
            <input type="text" id="ctb_id" name="ctb_settings[identifier]"
                class="regular-text" value="<?php echo esc_attr( $opts['identifier'] ); ?>" placeholder="you.bsky.social">
            <p class="description">Your Bluesky handle or DID.</p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ctb_pw">App Password</label></th>
        <td>
            <input type="password" id="ctb_pw" name="ctb_settings[app_password]"
                class="regular-text" value="<?php echo esc_attr( $opts['app_password'] ); ?>" autocomplete="new-password">
            <p class="description">Generate at <a href="https://bsky.app/settings/app-passwords" target="_blank" rel="noopener">bsky.app/settings/app-passwords</a></p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ctb_host">PDS Host</label></th>
        <td>
            <input type="url" id="ctb_host" name="ctb_settings[pds_host]"
                class="regular-text" value="<?php echo esc_attr( $opts['pds_host'] ); ?>">
            <p class="description">Leave as <code>https://bsky.social</code> unless you self-host a PDS.</p>
        </td>
    </tr>
</table>

<h2>Crossposting Behaviour</h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row">Post Types</th>
        <td>
            <?php foreach ( $post_types as $pt ) : ?>
            <label style="display:block;margin-bottom:5px">
                <input type="checkbox" name="ctb_settings[enabled_post_types][]"
                    value="<?php echo esc_attr( $pt->name ); ?>"
                    <?php checked( in_array( $pt->name, (array) $opts['enabled_post_types'], true ) ); ?>>
                <?php echo esc_html( $pt->labels->singular_name ); ?>
                <code style="font-size:11px;color:#777"><?php echo esc_html( $pt->name ); ?></code>
            </label>
            <?php endforeach; ?>
            <p class="description">For Social Notes from Tusky, post a note then check the <a href="?page=crosspost-to-bluesky&tab=debug">Debug Log</a> to see its post type slug, then enable it here.</p>
        </td>
    </tr>
    <tr>
        <th scope="row">Auto Crosspost</th>
        <td>
            <label>
                <input type="checkbox" name="ctb_settings[auto_crosspost]" value="1"
                    <?php checked( ! empty( $opts['auto_crosspost'] ) ); ?>>
                Automatically crosspost when a post is first published
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="ctb_tpl">Post Template</label></th>
        <td>
            <textarea id="ctb_tpl" name="ctb_settings[template]" rows="5" class="large-text code"><?php echo esc_textarea( $opts['template'] ); ?></textarea>
            <p class="description">
                Tokens: <code>{title}</code>, <code>{excerpt}</code>, <code>{content}</code>, <code>{url}</code>.<br>
                <code>{title}</code> is auto-removed for Social Notes. Max 300 characters sent to Bluesky.
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row">Append Permalink</th>
        <td>
            <label>
                <input type="checkbox" name="ctb_settings[include_permalink]" value="1"
                    <?php checked( ! empty( $opts['include_permalink'] ) ); ?>>
                Append the post URL at the end of the Bluesky post
            </label>
        </td>
    </tr>
</table>

<h2>Video</h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row">Video Uploads</th>
        <td>
            <label>
                <input type="checkbox" name="ctb_settings[video_enabled]" value="1"
                    <?php checked( ! empty( $opts['video_enabled'] ) ); ?>>
                Upload videos as native Bluesky videos (MP4, max 100&nbsp;MB)
            </label>
            <p class="description">
                When a post contains a video attachment, it is uploaded to Bluesky's video service and embedded natively.
                Video takes priority over images — Bluesky supports one or the other per post.<br>
                The upload polls Bluesky's processing queue for up to 2 minutes. For very large videos,
                consider using the <strong>Crosspost Now</strong> button on the post editor rather than auto-crosspost.
            </p>
        </td>
    </tr>
</table>

<h2>Developer</h2>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row">Debug Logging</th>
        <td>
            <label>
                <input type="checkbox" name="ctb_settings[debug_enabled]" value="1"
                    <?php checked( ! empty( $opts['debug_enabled'] ) ); ?>>
                Enable detailed debug logging
            </label>
            <p class="description">View logs on the <a href="?page=crosspost-to-bluesky&tab=debug">Debug Log tab</a>. Logs every HTTP call, image/video upload step, skip reason, and error.</p>
        </td>
    </tr>
</table>

<?php submit_button(); ?>
</form>

<?php elseif ( $active_tab === 'debug' ) : ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <h2 style="margin:0">Debug Log</h2>
    <button type="button" id="ctb-clear-log" class="button button-secondary">Clear Log</button>
</div>

<?php if ( empty( $log ) ) : ?>
    <p style="color:#666">No log entries yet. Enable debug logging in Settings and trigger a crosspost to see output here.</p>
<?php else : ?>
<table class="widefat striped" style="font-family:monospace;font-size:12px">
    <thead>
        <tr>
            <th style="width:155px">Time</th>
            <th style="width:58px">Level</th>
            <th>Message</th>
            <th style="width:90px">Context</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $log as $entry ) :
        $color = match( $entry['level'] ) { 'ERROR' => '#b32d2e', 'INFO' => '#1d7a2c', default => '#555' }; ?>
        <tr>
            <td style="white-space:nowrap"><?php echo esc_html( $entry['time'] ); ?></td>
            <td style="color:<?php echo esc_attr( $color ); ?>;font-weight:bold"><?php echo esc_html( $entry['level'] ); ?></td>
            <td><?php echo esc_html( $entry['message'] ); ?></td>
            <td>
                <?php if ( ! empty( $entry['context'] ) ) : ?>
                <details>
                    <summary style="cursor:pointer;color:#2271b1">details</summary>
                    <pre style="margin:4px 0;white-space:pre-wrap;font-size:11px;max-width:600px"><?php echo esc_html( wp_json_encode( $entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
                </details>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p style="color:#666;font-size:12px;margin-top:8px">Showing <?php echo count( $log ); ?> entries (newest first). Capped at 200.</p>
<?php endif; ?>

<?php endif; ?>

</div><!-- .tab-content -->
</div><!-- .wrap -->
