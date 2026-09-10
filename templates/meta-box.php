<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div style="font-size:13px">
    <?php if ( ! empty( $uri ) ) : ?>
        <p style="color:#1d7a2c;margin-bottom:8px">&#10003; Crossposted to Bluesky</p>
    <?php endif; ?>
    <?php if ( ! empty( $error ) ) : ?>
        <p style="color:#b32d2e;margin-bottom:8px">&#10007; <?php echo esc_html( $error ); ?></p>
    <?php endif; ?>
    <p>
        <label>
            <input type="checkbox" name="ctb_skip_crosspost" value="1" <?php checked( ! empty( $skip ) ); ?>>
            Skip crossposting for this post
        </label>
    </p>
    <?php if ( 'publish' === $post->post_status ) : ?>
    <p>
        <button type="button" class="button ctb-manual-btn"
            data-postid="<?php echo esc_attr( $post->ID ); ?>"
            data-nonce="<?php echo esc_attr( wp_create_nonce( 'ctb_manual_' . $post->ID ) ); ?>">
            <?php echo empty( $uri ) ? 'Crosspost Now' : 'Re-crosspost'; ?>
        </button>
        <span class="ctb-manual-result" style="margin-left:6px;font-weight:600"></span>
    </p>
    <?php endif; ?>
</div>
