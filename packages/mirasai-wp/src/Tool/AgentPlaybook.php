<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

/**
 * Job-to-channel routing for agents that just installed MirasAI.
 *
 * This is the first-call map: which tools to use for each job given only this
 * host, this host plus SSH, or this host plus mcp2cli / the local router.
 * Keep it in the MCP payload, not only in git docs — production agents never
 * see the repository.
 */
class AgentPlaybook
{
    public const VERSION = 2;

    /**
     * Short initialize text. Agents often skip long instructions; the full
     * matrix lives on system/diagnose.playbook.
     */
    public static function initializeInstructions(): string
    {
        return implode("\n", [
            'MirasAI WordPress host. This HTTP endpoint does not compile YOOtheme LESS.',
            'Call system/diagnose first and follow playbook. Do not trial-and-error Customizer or WP-CLI Style writes.',
            'Builder layouts: template/element-* on this host with if_match, dry_run, then confirm_guarded_write.',
            'Style CSS (theme.<id>.css): compile only if YOUR tools/list includes mirasai/style-preview. Then use mirasai/style-update. mcp2cli against this URL is still this host, not the compiler.',
            'If those router tools are absent, stop. Do not write theme_mods via WP-CLI. Customizer save() with dirty=false is a silent no-op.',
            'SSH: verify the CSS header and purge page cache (WP Rocket rocket_clean_domain). It does not regenerate CSS.',
            'Style JSON is theme_mods of the active stylesheet (a child is not theme_mods_yootheme). Proof of a write is the CSS compiled-on header, not a visible colour change.',
            'sandbox/execute-php is listed only when dangerous execution is enabled for this domain; each call needs confirm_execute_php=true.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        return [
            'version' => self::VERSION,
            'read_this_first' => 'Pick the route from the tools you can actually see. Do not invent a Customizer, WP-CLI, or browser path when a listed tool already covers the job.',
            'how_to_detect_your_channel' => [
                'this_host' => 'initialize.serverInfo.name is MirasAI (not @miras/mirasai-mcp) and tools/list has no mirasai/style-preview.',
                'router' => 'initialize.serverInfo.name is @miras/mirasai-mcp, or tools/list includes mirasai/style-preview.',
                'mcp2cli' => 'Same as whichever URL or stdio command you passed. mcp2cli --transport streamable against this host URL is this_host.',
                'ssh' => 'A shell on the CMS server. Not an MCP channel. Does not compile LESS.',
            ],
            'compiler_on_this_endpoint' => false,
            'compiler_present_iff' => 'mirasai/style-preview or mirasai/style-update appears in YOUR current tools/list (usually via local @miras/mirasai-mcp). This diagnose payload cannot see that list.',
            'depends_on' => self::dependsOn(),
            'channels' => self::channels(),
            'jobs' => self::jobs(),
            'anti_loops' => self::antiLoops(),
        ];
    }

    /**
     * What has to be true before a channel can do the jobs below.
     *
     * @return array<string, mixed>
     */
    private static function dependsOn(): array
    {
        return [
            'host_http' => [
                'Authenticated MCP (WordPress Application Password + manage_options). Anonymous 401/503 is a lock or missing auth, not a missing plugin.',
                'This plugin version actually deployed (a GitHub ZIP older than the playbook will not mention it).',
            ],
            'router' => [
                'A local machine with Node.js 20+ and @miras/mirasai-mcp (Cloud Agents without 1Password/SSH cannot compile).',
                'sites.json entry for the target site_id. default_site_id may be a different site — always pass site_id.',
                'style_worker_sha256 pinned to THAT site\'s wp-content/themes/yootheme/assets/admin/js/worker.js. Re-pin after a YOOtheme upgrade.',
                'Host credentials (op:// or env). The pin without credentials still cannot call the host.',
            ],
            'ssh' => [
                'A working user key to the account, not a Host alias that forces root.',
                'CageFS WP-CLI path when the account is jailed.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function channels(): array
    {
        return [
            'this_host' => [
                'use_for' => [
                    'site inspect',
                    'content read/translate',
                    'YOOtheme Builder layouts',
                    'Style read/diagnose',
                    'ACF read',
                    'guarded Style write only when you already have compiled LTR/RTL CSS',
                ],
                'cannot' => [
                    'compile YOOtheme LESS',
                    'regenerate theme.<id>.css from config alone',
                    'reach the endpoint when anonymous access is locked (503/401)',
                ],
                'storage' => 'Style JSON is theme_mods_{get_stylesheet()}.config. A child theme is not the parent theme_mods_yootheme row. The yootheme option holds Builder templates.',
            ],
            'router' => [
                'binary' => 'mirasai-mcp serve',
                'package' => '@miras/mirasai-mcp',
                'use_for' => [
                    'YOOtheme Style preview, compile, guarded write, verify',
                    'multi-site routing',
                    'everything this host can do, proxied',
                ],
                'cannot' => [
                    'compile if style_worker_sha256 is missing or mismatches worker.js',
                    'write the intended site if you omit site_id and default_site_id is elsewhere',
                ],
                'requires' => [
                    'Node.js 20+',
                    'sites.json entry with style_worker_sha256 pinned for this site_id',
                    'host credentials for that site',
                ],
            ],
            'mcp2cli' => [
                'use_for' => 'Whatever MCP it is pointed at. Pointed at this host: same as this_host. Pointed at mirasai-mcp serve (stdio): same as router.',
                'do_not_assume' => 'Having mcp2cli does not mean you have the Style compiler.',
                'cli_gotchas' => [
                    'The --dry-run flag is store_true. Omitting it still sends dry_run=true to the tool. A real write needs JSON with dry_run=false and confirm_guarded_write=true (typically --stdin).',
                    'Pass --site-id when the router default is not the target site.',
                ],
            ],
            'ssh' => [
                'use_for' => [
                    'backups',
                    'CageFS WP-CLI: /opt/cpanel/ea-php84/root/usr/bin/php -d error_reporting=0 /usr/local/bin/wp --path=<site>',
                    'read the first line of theme.<id>.css (compiled on …)',
                    'purge WP Rocket with rocket_clean_domain() via wp eval; the Rocket CLI does not expose purge',
                ],
                'cannot' => [
                    'regenerate YOOtheme CSS',
                    'make style-read.stale_sources flip by editing theme_mods',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jobs(): array
    {
        return [
            [
                'id' => 'inspect_site',
                'do' => 'See what is installed and which tools exist.',
                'best' => 'system/diagnose (this payload) then system/info.',
                'avoid' => ['guessing missing addons from a 404'],
            ],
            [
                'id' => 'content',
                'do' => 'Read or translate posts/pages/terms.',
                'best' => 'content/list, content/read, then content/translate with if_match. MirasAI does not auto-translate.',
                'ssh' => 'Unnecessary unless debugging WPML/Polylang outside MCP.',
            ],
            [
                'id' => 'yootheme_builder',
                'do' => 'Change Builder elements, props, sources, templates, widgets.',
                'best' => 'template/list → template/element-read → template/element-schema if needed → template/element-* with if_match, dry_run, confirm_guarded_write.',
                'not_the_same_as' => 'YOOtheme Style / theme.css. Builder JSON does not go through less.js.',
                'avoid' => ['WP-CLI on post meta', 'editing layout JSON on disk', 'Customizer'],
            ],
            [
                'id' => 'yootheme_style_read',
                'do' => 'Inspect Style variables, custom Less, compiled CSS freshness.',
                'best' => 'template/style-read. Optional template/style-sources for the import tree.',
                'caveat' => 'compiled.stale_sources is a Less-file mtime heuristic. It stays false after config-only edits (theme_mods_{stylesheet}.config less/custom_less). compiled.stale_config is not detected on this host.',
                'storage' => 'theme_mods_{get_stylesheet()}.config. Child themes: theme_mods_<child>, not theme_mods_yootheme.',
                'ssh' => 'Optional: head -n1 wp-content/themes/yootheme/css/theme.<id>.css',
            ],
            [
                'id' => 'yootheme_style_write',
                'do' => 'Change Style variables or custom Less and regenerate the served CSS.',
                'best_if_router_tools_listed' => 'mirasai/style-update: dry_run=true, review, then dry_run=false + confirm_guarded_write=true + fresh if_match. Empty vars recompiles the current config. Pass site_id. Use --stdin JSON so dry_run is not left at the CLI default.',
                'if_only_this_host' => 'STOP. template/style-update requires compiled_css and compiled_rtl you cannot produce here. Do not write theme_mods via WP-CLI. Do not open the Customizer as the default path.',
                'proof' => 'New etag + CSS /* compiled on */ header moved. #fff vs #FFFFFF (and similar hex minify) may leave CSS bytes unchanged; that is not a failed write.',
                'last_resort_browser' => 'Only when the router is unavailable: Customizer with &site=<url>, wait for iframe, change a real control and undo (this starts less.js), then await yootheme.store.useConfigStore().save(), then check the CSS compiled-on header. save() with dirty=false writes nothing.',
                'avoid' => [
                    'cs.save() without a real control change',
                    'cs.change() expecting a compile',
                    'WP-CLI option update of theme_mods_yootheme or the child row',
                    'calling host template/style-update without router-compiled CSS hashes',
                    'writing the parent theme_mods_yootheme row when a child stylesheet is active',
                ],
            ],
            [
                'id' => 'verify_css_on_disk',
                'do' => 'Prove the stylesheet actually changed.',
                'best_if_router_tools_listed' => 'mirasai/style-verify, then template/style-read. Treat fresh=true with caution: it is not a byte comparison and ignores config-only staleness.',
                'ssh' => 'Read the first line: /* YOOtheme Pro v… compiled on <ISO8601> */. Count tokens with grep -o, never grep -c. Decimals minify (-0.03em → -.03em).',
            ],
            [
                'id' => 'purge_page_cache',
                'do' => 'After Style CSS write, drop HTML caches that still embed the old ?ver= mtime.',
                'best' => 'SSH wp eval that calls rocket_clean_domain() when WP Rocket is present. This host does not purge page caches yet.',
                'avoid' => ['assuming object-cache delete is enough', 'wp rocket CLI purge — it is not exposed'],
            ],
            [
                'id' => 'files_db_sandbox',
                'do' => 'Read files, schema, or run constrained SQL/PHP.',
                'best' => 'file/read, file/list, db/schema, db/query. sandbox/execute-php only when diagnose says dangerous_exec_available.',
                'ssh' => 'Fine for backups and CageFS WP-CLI. Do not use it as a Style compiler.',
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function antiLoops(): array
    {
        return [
            [
                'id' => 'customizer_save_noop',
                'symptom' => 'Customizer save() returns success; CSS compiled-on header unchanged.',
                'cause' => 'dirty was false. save() is a silent no-op. change() only marks dirty and does not start less.js.',
                'fix' => 'Use mirasai/style-update when available. Otherwise touch a real control, undo, then save(), then check the CSS header.',
            ],
            [
                'id' => 'wpcli_or_sql_style_config',
                'symptom' => 'theme_mods config has new less vars; frontend still serves old CSS.',
                'cause' => 'YOOtheme Pro 5 has no server-side compiler. Frontend never rebuilds CSS.',
                'fix' => 'Do not write Style config via WP-CLI. Use mirasai/style-update so config and CSS stay in lockstep.',
            ],
            [
                'id' => 'host_style_update_missing_css',
                'symptom' => 'template/style-update errors on compiled_css or hash mismatch.',
                'cause' => 'You are on the host. Compilation lives in the local router.',
                'fix' => 'Switch mcp2cli/client to mirasai-mcp serve, or stop. Do not fall through to the Customizer by default.',
            ],
            [
                'id' => 'stale_sources_false_negative',
                'symptom' => 'style-read reports stale_sources=false after a config-only change.',
                'cause' => 'The heuristic compares CSS mtime with Less files, not with stored config. wp_options has no updated_at.',
                'fix' => 'Ignore stale_sources for config-only edits. Recompile via the router, or treat CSS as stale until the compiled-on header moves.',
            ],
            [
                'id' => 'mcp2cli_host_is_not_router',
                'symptom' => 'mcp2cli --list shows template/style-update but not mirasai/style-preview.',
                'cause' => 'mcp2cli is pointed at the CMS HTTP endpoint.',
                'fix' => 'Keep that for Builder/content. For Style compile, point mcp2cli at `mirasai-mcp serve` (stdio) with a pinned style_worker_sha256.',
            ],
            [
                'id' => 'child_theme_parent_mods',
                'symptom' => 'style-update returns stale_etag / Style config changed at the write gate. CSS header unchanged.',
                'cause' => 'Read used get_theme_mod(config) on the child; write compared theme_mods_yootheme (parent). Different JSON, CAS abort.',
                'fix' => 'Write theme_mods_{get_stylesheet()}. storage.option on style-read must match that row.',
            ],
            [
                'id' => 'router_wrong_site_or_unpinned_worker',
                'symptom' => 'Compile hits the wrong site, style_worker_hash_required, or hash_mismatch.',
                'cause' => 'default_site_id is another site, or worker.js changed after a YOOtheme upgrade and the pin did not.',
                'fix' => 'Pass site_id every call. Re-pin style_worker_sha256 to the live worker.js after reviewing the hash.',
            ],
            [
                'id' => 'mcp2cli_dry_run_omitted',
                'symptom' => 'style-update reports ok but dry_run=true; nothing written.',
                'cause' => 'mcp2cli --dry-run is store_true and the tool defaults dry_run to true when the field is omitted.',
                'fix' => 'Send JSON with dry_run=false and confirm_guarded_write=true via --stdin. Do not confirm twice without a fresh etag.',
            ],
            [
                'id' => 'hex_minify_same_css_bytes',
                'symptom' => 'Config shows #fff instead of #FFFFFF but CSS sha256/bytes look unchanged.',
                'cause' => 'Less minifies equivalent hex. A recompile can also rewrite the compiled-on header without a visible colour change.',
                'fix' => 'Prove the write with style-read (var + storage.option) and the CSS compiled-on header, not a screenshot.',
            ],
            [
                'id' => 'unauthenticated_host',
                'symptom' => 'Host MCP returns 401 or 503. Agent assumes MirasAI is not installed.',
                'cause' => 'Anonymous REST is locked or Application Password was not sent. Site-lock plugins often 503 the frontend and unauthenticated REST.',
                'fix' => 'Call with Application Password. 503 anonymous + 200 authenticated is expected on locked staging sites.',
            ],
        ];
    }
}
