<?php

namespace MWAssistant\Special;

use SpecialPage;
use MWAssistant\MCP\EmbeddingsClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use MediaWiki\Widget\TitleInputWidget;

/**
 * Special page that shows embedding statistics and allows updating vectors
 * for either a single page or an entire namespace.
 *
 * Responsibilities:
 *  - Display embedding index statistics from the MCP server
 *  - Trigger batch updates for an entire namespace
 *  - Trigger single-page embedding updates
 *  - Provide autocomplete input for page titles
 */
class SpecialMWAssistantEmbeddings extends SpecialPage
{

    /** @var EmbeddingsClient */
    private $client;

    /** @var MediaWikiServices */
    private $services;

    public function __construct()
    {
        parent::__construct('MWAssistantEmbeddings', 'mwassistant-use');
        $this->client = new EmbeddingsClient();
        $this->services = MediaWikiServices::getInstance();
    }

    /**
     * Entry point for the special page.
     */
    public function execute($subPage)
    {
        $this->checkPermissions();

        $request = $this->getRequest();
        $output = $this->getOutput();
        $output->setPageTitle('Vector Embeddings Status');

        // Widgets + RL modules
        $output->addModules([
            'ext.mwassistant.embeddings',
            'mediawiki.widgets',
        ]);
        $output->enableOOUI();

        $output->addHTML('<div class="mwassistant-dashboard">');

        // ----- Handle Batch Update -----
        if ($request->wasPosted() && $request->getCheck('batch_update')) {
            $ns = (int) $request->getInt('namespace_selector');
            $this->handleBatchUpdate($ns);
        }

        // ----- Handle Manual Update -----
        if ($request->wasPosted() && $request->getText('page')) {
            $page = $request->getText('page');
            $this->handleSingleUpdate($page);
        }

        // ----- Render main stats table -----
        $this->renderStatsTable();

        $output->addHTML('</div>');
    }

    /* ============================================================
       Batch Update Handler
       ============================================================ */

    /**
     * Run batch embedding updates for a namespace.
     */
    private function handleBatchUpdate(int $namespace)
    {
        $output = $this->getOutput();
        $user = $this->getUser();

        try {
            $stats = $this->client->getStats($user);
            $mcpTimestamps = $stats['page_timestamps'] ?? [];

            $dbr = $this->services->getDBLoadBalancer()->getConnection(DB_REPLICA);
            $res = $dbr->newSelectQueryBuilder()
                ->select(['page_id', 'page_namespace', 'page_title', 'page_touched'])
                ->from('page')
                ->where([
                    'page_namespace' => $namespace,
                    'page_is_redirect' => 0,
                ])
                ->caller(__METHOD__)
                ->fetchResultSet();

            $updated = 0;
            $skipped = 0;
            $errors = 0;
            $lastErr = null;

            set_time_limit(0);

            foreach ($res as $row) {
                $titleObj = Title::newFromRow($row);
                $prefixed = $titleObj->getPrefixedText();
                $mwTouched = $row->page_touched;

                $needsUpdate = !isset($mcpTimestamps[$prefixed]) ||
                    $mcpTimestamps[$prefixed] < $mwTouched;

                if (!$needsUpdate) {
                    $skipped++;
                    continue;
                }

                $wikiPage = $this->services->getWikiPageFactory()->newFromTitle($titleObj);
                $content = $wikiPage->getContent();
                $text = $content ? \ContentHandler::getContentText($content) : '';

                if (!$text) {
                    $skipped++;
                    continue;
                }

                $res = $this->client->updatePage($user, $prefixed, $text, $mwTouched);
                if (isset($res['error'])) {
                    $errors++;
                    $lastErr = $res['message'];
                } else {
                    $updated++;
                }
            }

            $msg = "Batch processed for namespace $namespace.<br>" .
                "Updated: <b>$updated</b><br>" .
                "Skipped: $skipped<br>" .
                "Errors: $errors" .
                ($errors && $lastErr ? "<br>Last Error: $lastErr" : "");

            $output->addHTML(Html::successBox($msg));

        } catch (\Exception $e) {
            $output->addHTML(
                Html::errorBox("Batch update failed: " . htmlspecialchars($e->getMessage()))
            );
        }
    }

    /* ============================================================
       Single Page Update Handler
       ============================================================ */

    /**
     * Update embeddings for a single page.
     */
    private function handleSingleUpdate(string $pageName)
    {
        $output = $this->getOutput();
        $user = $this->getUser();

        $title = Title::newFromText($pageName);
        if (!$title || !$title->exists()) {
            $output->addHTML(Html::errorBox("Page does not exist: " . htmlspecialchars($pageName)));
            return;
        }

        $wikiPage = $this->services->getWikiPageFactory()->newFromTitle($title);
        $content = $wikiPage->getContent();
        $text = $content ? \ContentHandler::getContentText($content) : '';

        if (!$text) {
            $output->addHTML(Html::errorBox("No text content found for page."));
            return;
        }

        $timestamp = $wikiPage->getTimestamp();
        $res = $this->client->updatePage(
            $user,
            $title->getPrefixedText(),
            $text,
            $timestamp
        );

        if (isset($res['error'])) {
            $output->addHTML(Html::errorBox($res['message']));
        } else {
            $output->addHTML(
                Html::successBox(
                    "Successfully updated embedding for: " . htmlspecialchars($title->getPrefixedText())
                )
            );
        }
    }

    /* ============================================================
       Stats + UI Rendering
       ============================================================ */

    /**
     * Render namespace-level statistics and controls.
     */
    private function renderStatsTable()
    {
        $output = $this->getOutput();
        $user = $this->getUser();

        try {
            $stats = $this->client->getStats($user);
            $error = isset($stats['error']);
            $mcpTimestamps = $error ? [] : ($stats['page_timestamps'] ?? []);
            $totalVectors = $error ? 0 : ($stats['total_vectors'] ?? 0);
        } catch (\Exception $e) {
            $output->addHTML(Html::errorBox("Could not fetch embedding statistics: " . $e->getMessage()));
            return;
        }

        if ($error) {
            $output->addHTML(
                Html::errorBox("Error fetching stats: " . htmlspecialchars($stats['message'] ?? "Unknown error"))
            );
        }

        // ---- Stats Card ----
        $output->addHTML('<div class="mwassistant-stats-grid">');
        $output->addHTML(
            '<div class="mwassistant-stat-card">
                <div class="mwassistant-stat-label">Total Vectors</div>
                <div class="mwassistant-stat-value">' . htmlspecialchars($totalVectors) . '</div>
             </div>'
        );
        $output->addHTML('</div>');

        // ---- Namespace Status Table ----
        $nsStats = $this->computeNamespaceStats($mcpTimestamps);
        $this->renderNamespaceTable($nsStats);

        // ---- Single Page Update Form ----
        $this->renderSingleUpdateForm();
    }

    /**
     * Build namespace summary statistics.
     */
    private function computeNamespaceStats(array $mcpTimestamps): array
    {
        $dbr = $this->services->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $nsInfo = $this->services->getNamespaceInfo();
        $validNS = [];

        // Build allowed namespaces
        foreach ($nsInfo->getCanonicalNamespaces() as $nsId => $nsName) {
            if ($nsId >= 0 && !$nsInfo->isTalk($nsId) && $nsId !== NS_USER) {
                $validNS[$nsId] = ($nsId === 0) ? '(Main)' : $nsName;
            }
        }
        ksort($validNS);

        $res = $dbr->newSelectQueryBuilder()
            ->select(['page_namespace', 'page_title', 'page_touched', 'page_len'])
            ->from('page')
            ->where([
                'page_namespace' => array_keys($validNS),
                'page_is_redirect' => 0,
            ])
            ->caller(__METHOD__)
            ->fetchResultSet();

        $stats = [];
        foreach ($validNS as $nsId => $name) {
            $stats[$nsId] = [
                'name' => $name,
                'total' => 0,
                'synced' => 0,
                'outdated' => 0,
                'missing' => 0,
                'skipped' => 0,
            ];
        }

        foreach ($res as $row) {
            $nsId = (int) $row->page_namespace;
            $titleObj = Title::makeTitle($nsId, $row->page_title);
            $prefixed = $titleObj->getPrefixedText();
            $mwTouched = $row->page_touched;
            $pageLen = (int) $row->page_len;

            $stats[$nsId]['total']++;

            if (isset($mcpTimestamps[$prefixed])) {
                $stats[$nsId][
                    $mcpTimestamps[$prefixed] >= $mwTouched ? 'synced' : 'outdated'
                ]++;
            } else {
                // Match server’s heuristic: skip pages too short to embed
                if ($pageLen < 10) {
                    $stats[$nsId]['skipped']++;
                } else {
                    $stats[$nsId]['missing']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Render the namespace status table.
     */
    private function renderNamespaceTable(array $stats)
    {
        $output = $this->getOutput();
        $action = htmlspecialchars($this->getPageTitle()->getLocalURL());

        $html = '<h3 class="mwassistant-section-header">Namespace Status</h3>';
        $html .= '<table class="mwassistant-table"><thead><tr>
                    <th>Namespace</th>
                    <th>Total</th>
                    <th>Synced</th>
                    <th>Outdated</th>
                    <th>Skipped (&lt;10 chars)</th>
                    <th>Missing</th>
                    <th>Action</th>
                 </tr></thead><tbody>';

        foreach ($stats as $nsId => $row) {

            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['name']) . '</td>';
            $html .= '<td>' . $row['total'] . '</td>';

            // Pills
            $html .= '<td><span class="mwassistant-status-pill synced"><span class="label">Synced</span><span class="count">' . $row['synced'] . '</span></span></td>';
            $html .= '<td>' . ($row['outdated'] ?
                '<span class="mwassistant-status-pill out-of-date"><span class="label">Outdated</span><span class="count">' . $row['outdated'] . '</span></span>' :
                '0'
            ) . '</td>';
            $html .= '<td>' . ($row['skipped'] ?
                '<span class="mwassistant-status-pill skipped"><span class="label">Skipped</span><span class="count">' . $row['skipped'] . '</span></span>' :
                '0'
            ) . '</td>';
            $html .= '<td>' . ($row['missing'] ?
                '<span class="mwassistant-status-pill not-embedded"><span class="label">Missing</span><span class="count">' . $row['missing'] . '</span></span>' :
                '0'
            ) . '</td>';

            // Action button
            $html .= '<td>
                        <form method="post" action="' . $action . '" style="margin:0;">
                            ' . Html::hidden('namespace_selector', $nsId) . '
                            <button type="submit" name="batch_update" value="1" class="mwassistant-action-btn">Update</button>
                        </form>
                      </td>';

            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        $output->addHTML($html);
    }

    /**
     * Render the single page update UI section.
     */
    private function renderSingleUpdateForm()
    {
        $output = $this->getOutput();
        $action = htmlspecialchars($this->getPageTitle()->getLocalURL());

        $output->addHTML('<h3 class="mwassistant-section-header">Single Page Update</h3>');
        $output->addHTML(
            '<form method="post" action="' . $action . '" class="mwassistant-single-update-card">'
        );
        $output->addHTML(
            '<label for="page-input" style="font-weight:600;">Page Title:</label>'
        );

        // Title widget with autocomplete enabled
        $widget = $this->buildTitleWidget();
        $output->addHTML($widget->toString());

        $output->addHTML(
            '<button type="submit" class="mwassistant-action-btn" style="margin-left:10px;">
                Update Embedding
             </button>'
        );
        $output->addHTML('</form>');
    }

    /**
     * Build the OOUI TitleInputWidget with autocomplete configured.
     */
    private function buildTitleWidget(): TitleInputWidget
    {
        return new TitleInputWidget([
            'id' => 'page-input',
            'name' => 'page',
            'placeholder' => 'e.g. Main_Page',
            'required' => true,
            'infusable' => true,
            'autocomplete' => true,
            'showIcons' => true,
            'showRedirects' => true,
            'contentPagesOnly' => false,
            'namespace' => null,
            'apiUrl' => wfScript('api'),
            'classes' => ['mwassistant-input-widget'],
        ]);
    }
}
