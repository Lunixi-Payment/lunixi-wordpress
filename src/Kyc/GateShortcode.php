<?php

declare(strict_types=1);

namespace Lunixi\WP\Kyc;

use Lunixi\Sdk\Kyc\AchievedAssurance;
use Lunixi\WP\Core\Settings;

defined('ABSPATH') || exit;

/**
 * `[lunixi_kyc_gate identity="SUBSTANTIAL" address="LOW"]…protected…[/lunixi_kyc_gate]`
 * — shows the enclosed content only to users who meet the per-domain assurance
 * requirement (state + expiry honoured via VerificationStore). Otherwise renders
 * a verify CTA. The seam for membership / gated content.
 */
final class GateShortcode
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        add_shortcode('lunixi_kyc_gate', [$this, 'render']);
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public function render($atts, ?string $content = null): string
    {
        $atts = shortcode_atts(
            ['identity' => '', 'address' => '', 'aml' => ''],
            is_array($atts) ? $atts : [],
            'lunixi_kyc_gate'
        );

        $requirements = [];
        $map = [
            'identity' => AchievedAssurance::IDENTITY,
            'address' => AchievedAssurance::ADDRESS,
            'aml' => AchievedAssurance::AML,
        ];
        foreach ($map as $attr => $domain) {
            $level = strtoupper((string) $atts[$attr]);
            if (in_array($level, ['LOW', 'SUBSTANTIAL', 'HIGH'], true)) {
                $requirements[$domain] = $level;
            }
        }
        if ($requirements === []) {
            $requirements = [AchievedAssurance::IDENTITY => 'LOW'];
        }

        if (Gate::isVerified(null, $requirements)) {
            return do_shortcode((string) $content);
        }

        $page = $this->settings->kycPageUrl();
        $cta = $page !== ''
            ? ' <a href="' . esc_url($page) . '">' . esc_html__('Verify now', 'lunixi') . '</a>'
            : '';
        return '<div class="lunixi-kyc-gate-locked">' . esc_html__('This content requires identity verification.', 'lunixi') . $cta . '</div>';
    }
}
