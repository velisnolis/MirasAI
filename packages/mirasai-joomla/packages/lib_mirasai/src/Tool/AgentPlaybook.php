<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

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
    public const VERSION = 3;

    /**
     * Short initialize prefix. Agents often skip long instructions; the full
     * matrix lives on system/diagnose.playbook.
     */
    public static function initializeInstructions(): string
    {
        return implode("\n", [
            'MirasAI Joomla host. This HTTP endpoint does not compile YOOtheme LESS.',
            'Call system/diagnose first and follow playbook. Do not use Customizer, WP-CLI, or SQL for YOOtheme Style writes.',
            'Builder layouts: use template/element-* on this host with if_match, dry_run, then confirm_guarded_write.',
            'Style CSS: only compile when your tools/list includes mirasai/style-preview; then use mirasai/style-update on the local router.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        return [
            'version' => self::VERSION,
            'read_this_first' => 'Pick the route from the tools you can actually see. Do not invent a Customizer, SQL, or browser path when a listed tool already covers the job.',
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
                'Authenticated MCP (Joomla API token, Super User gated). Anonymous 401 is missing auth, not a missing package.',
                'This package version actually deployed.',
            ],
            'router' => [
                'A local machine with Node.js 20+ and @miras/mirasai-mcp (Cloud Agents without 1Password/SSH cannot compile).',
                'sites.json entry for the target site_id. default_site_id may be a different site — always pass site_id.',
                'style_worker_sha256 pinned to THAT site\'s templates/yootheme/assets/admin/js/worker.js. Re-pin after a YOOtheme upgrade.',
                'Host credentials (op:// or env).',
            ],
            'ssh' => [
                'A working user key to the account, not a Host alias that forces root.',
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
                    'guarded Style write only when you already have compiled LTR/RTL CSS',
                ],
                'cannot' => [
                    'compile YOOtheme LESS',
                    'regenerate theme.<id>.css from config alone',
                    'reach the endpoint without a valid API token',
                ],
                'storage' => 'Style JSON lives in #__template_styles.params.config, not in Builder module JSON.',
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
                    'read the first line of templates/yootheme/css/theme.<id>.css (compiled on …)',
                    'purge page cache after a CSS write',
                ],
                'cannot' => [
                    'regenerate YOOtheme CSS',
                    'make style-read.stale_sources flip by editing template style params',
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
                'do' => 'Read or translate articles/categories.',
                'best' => 'content/list, content/read, then content/translate with the source if_match. MirasAI does not auto-translate. For Builder articles, use yootheme_translatable_nodes[].replacement_key.',
                'ssh' => 'Unnecessary unless debugging language associations outside MCP.',
            ],
            [
                'id' => 'yootheme_builder',
                'do' => 'Change Builder elements, props, sources, templates, modules.',
                'best' => 'template/list → template/element-read → template/element-schema if needed → template/element-* with if_match, dry_run, confirm_guarded_write.',
                'not_the_same_as' => 'YOOtheme Style / theme.css. Builder JSON does not go through less.js.',
                'avoid' => ['SQL on #__content fulltext', 'editing layout JSON on disk', 'Customizer'],
            ],
            [
                'id' => 'yootheme_style_read',
                'do' => 'Inspect Style variables, custom Less, compiled CSS freshness.',
                'best' => 'template/style-read. Optional template/style-sources for the import tree.',
                'caveat' => 'compiled.stale_sources is a Less-file mtime heuristic. It stays false after config-only edits (#__template_styles.params.config less/custom_less). compiled.stale_config is not detected on this host.',
                'ssh' => 'Optional: head -n1 templates/yootheme/css/theme.<id>.css',
            ],
            [
                'id' => 'yootheme_style_write',
                'do' => 'Change Style variables or custom Less and regenerate the served CSS.',
                'best_if_router_tools_listed' => 'mirasai/style-update: dry_run=true, review, then dry_run=false + confirm_guarded_write=true + fresh if_match. Empty vars recompiles the current config. Pass site_id. Use --stdin JSON so dry_run is not left at the CLI default.',
                'if_only_this_host' => 'STOP. template/style-update requires compiled_css and compiled_rtl you cannot produce here. Do not write template style params via SQL. Do not open the Customizer as the default path.',
                'proof' => 'New etag + CSS /* compiled on */ header moved. #fff vs #FFFFFF (and similar hex minify) may leave CSS bytes unchanged; that is not a failed write.',
                'last_resort_browser' => 'Only when the router is unavailable: Customizer, wait for iframe, change a real control and undo (this starts less.js), then await save(), then check the CSS compiled-on header. save() with dirty=false writes nothing.',
                'avoid' => [
                    'save() without a real control change',
                    'change() expecting a compile',
                    'SQL updates of #__template_styles.params',
                    'calling host template/style-update without router-compiled CSS hashes',
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
                'do' => 'After Style CSS write, drop HTML caches that still embed the old CSS query string.',
                'best' => 'SSH or Joomla cache clean for the groups this host already clears on style-update (com_templates, yootheme, page). Page-cache extensions still need their own purge.',
                'avoid' => ['assuming a params write refreshed the frontend CSS'],
            ],
            [
                'id' => 'files_db_sandbox',
                'do' => 'Read files, schema, or run constrained SQL/PHP.',
                'best' => 'file/read, file/list, db/schema, db/query. file/write and sandbox/execute-php need staging or production elevation.',
                'ssh' => 'Fine for backups. Do not use it as a Style compiler.',
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
                'id' => 'sql_style_config',
                'symptom' => 'template style params have new less vars; frontend still serves old CSS.',
                'cause' => 'YOOtheme Pro 5 has no server-side compiler. Frontend never rebuilds CSS.',
                'fix' => 'Do not write Style config via SQL. Use mirasai/style-update so config and CSS stay in lockstep.',
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
                'cause' => 'The heuristic compares CSS mtime with Less files, not with stored config.',
                'fix' => 'Ignore stale_sources for config-only edits. Recompile via the router, or treat CSS as stale until the compiled-on header moves.',
            ],
            [
                'id' => 'mcp2cli_host_is_not_router',
                'symptom' => 'mcp2cli --list shows template/style-update but not mirasai/style-preview.',
                'cause' => 'mcp2cli is pointed at the CMS HTTP endpoint.',
                'fix' => 'Keep that for Builder/content. For Style compile, point mcp2cli at `mirasai-mcp serve` (stdio) with a pinned style_worker_sha256.',
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
                'fix' => 'Prove the write with style-read and the CSS compiled-on header, not a screenshot.',
            ],
            [
                'id' => 'unauthenticated_host',
                'symptom' => 'Host MCP returns 401. Agent assumes MirasAI is not installed.',
                'cause' => 'Anonymous REST is rejected. The Joomla API token was not sent.',
                'fix' => 'Call with a Super User API token.',
            ],
        ];
    }
}
