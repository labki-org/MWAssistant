<?php

namespace MWAssistant\Special;

use SpecialPage;
use MWAssistant\MCP\EmbeddingsClient;
use MediaWiki\MediaWikiServices;
use MediaWiki\Html\Html;
use MediaWiki\Title\Title;
use MediaWiki\SiteStats\SiteStats;

class SpecialMWAssistantEmbeddings extends SpecialPage
{
    public function __construct()
    {
        parent::__construct('MWAssistantEmbeddings', 'mwassistant-use');
    }

    public function execute($subPage)
    {
        $this->checkPermissions();
        $request = $this->getRequest();

        $output = $this->getOutput();
        $output->setPageTitle('Vector Embeddings Status');
        $output->addModules('ext.mwassistant.embeddings');

        $client = new EmbeddingsClient();
        $user = $this->getUser();
        $services = MediaWikiServices::getInstance();
        $dbr = $services->getDBLoadBalancer()->getConnection(DB_REPLICA);

        $output->addHTML('<div class="mwassistant-dashboard">');

        // --- Handle Batch Update ---
        if ($request->wasPosted() && $request->getCheck('batch_update')) {
            $namespace = (int) $request->getInt('namespace_selector');
            // ... [Logic remains same, focusing on UI output] ...
            // Re-implementing logic for batch update to ensure variables are captured
            try {
                $stats = $client->getStats($user);
                $mcpTimestamps = $stats['page_timestamps'] ?? [];

                $res = $dbr->newSelectQueryBuilder()
                    ->select(['page_id', 'page_namespace', 'page_title', 'page_touched'])
                    ->from('page')
                    ->where([
                        'page_namespace' => $namespace,
                        'page_is_redirect' => 0
                    ])
                    ->caller(__METHOD__)
                    ->fetchResultSet();

                $updatedCount = 0;
                $skippedCount = 0;
                $errorCount = 0;
                set_time_limit(0);

                foreach ($res as $row) {
                    $titleObj = Title::newFromRow($row);
                    $prefixedTitle = $titleObj->getPrefixedText();
                    $mwTouched = $row->page_touched;

                    $needsUpdate = true;
                    if (isset($mcpTimestamps[$prefixedTitle])) {
                        $mcpTs = $mcpTimestamps[$prefixedTitle];
                        if ($mcpTs >= $mwTouched) {
                            $needsUpdate = false;
                        }
                    }

                    if ($needsUpdate) {
                        $wikiPage = $services->getWikiPageFactory()->newFromTitle($titleObj);
                        $content = $wikiPage->getContent();
                        $text = $content ? \ContentHandler::getContentText($content) : '';

                        if ($text) {
                            $res = $client->updatePage($user, $prefixedTitle, $text, $mwTouched);
                            if (isset($res['error'])) {
                                $errorCount++;
                                $lastError = $res['message'];
                            } else {
                                $updatedCount++;
                            }
                        } else {
                            $skippedCount++;
                        }
                    } else {
                        $skippedCount++;
                    }
                }

                $msg = "Batch processed for namespace $namespace.<br>" .
                    "Updated: <b>$updatedCount</b><br>" .
                    "Skipped: $skippedCount<br>" .
                    "Errors: $errorCount" . ($errorCount > 0 && isset($lastError) ? "<br>Last Error: $lastError" : "");

                $output->addHTML(Html::successBox($msg));

            } catch (\Exception $e) {
                $output->addHTML(Html::errorBox("Batch update failed: " . $e->getMessage()));
            }
        }


        // --- Handle Single Page Manual Update ---
        if ($request->wasPosted() && $request->getText('page')) {
            $pageName = $request->getText('page');
            if ($pageName) {
                $title = Title::newFromText($pageName);
                if ($title && $title->exists()) {
                    $wikiPage = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle($title);
                    $content = $wikiPage->getContent();
                    $text = $content ? \ContentHandler::getContentText($content) : '';
                    $timestamp = $wikiPage->getTimestamp();

                    if ($text) {
                        $res = $client->updatePage($user, $title->getPrefixedText(), $text, $timestamp);
                        if (isset($res['error'])) {
                            $output->addHTML(Html::errorBox($res['message']));
                        } else {
                            $output->addHTML(Html::successBox("Successfully updated embedding for: " . $title->getPrefixedText()));
                        }
                    } else {
                        $output->addHTML(Html::errorBox("No text content found for page."));
                    }
                } else {
                    $output->addHTML(Html::errorBox("Page does not exist."));
                }
            }
        }

        // --- Stats & Display ---
        try {
            $stats = $client->getStats($user);
            $isError = isset($stats['error']);
            $mcpTimestamps = $isError ? [] : ($stats['page_timestamps'] ?? []);
            $totalVectors = $isError ? 0 : ($stats['total_vectors'] ?? 0);

            if ($isError) {
                $output->addHTML(Html::errorBox("Error fetching stats: " . ($stats['message'] ?? 'Unknown error')));
            }

            // Stats Card
            $output->addHTML('<div class="mwassistant-stats-grid">');
            $output->addHTML('<div class="mwassistant-stat-card">
                                <div class="mwassistant-stat-label">Total Vectors</div>
                                <div class="mwassistant-stat-value">' . htmlspecialchars($totalVectors) . '</div>
                              </div>');
            // We could add more cards here like "Total Pages Processed" or "Last Sync Time" if we tracked it
            $output->addHTML('</div>');


            // Table
            $output->addHTML('<h3 class="mwassistant-section-header">Namespace Status</h3>');

            // ... [Namespace Logic similar to before] ...
            $nsInfo = $services->getNamespaceInfo();
            $validNamespaces = [];
            foreach ($nsInfo->getCanonicalNamespaces() as $nsId => $nsName) {
                if ($nsId >= 0 && !$nsInfo->isTalk($nsId) && $nsId !== NS_USER) {
                    $validNamespaces[$nsId] = $nsId === 0 ? '(Main)' : $nsName;
                }
            }
            if (!isset($validNamespaces[0]))
                $validNamespaces[0] = '(Main)';
            ksort($validNamespaces);

            $res = $dbr->newSelectQueryBuilder()
                ->select(['page_namespace', 'page_title', 'page_touched'])
                ->from('page')
                ->where(['page_namespace' => array_keys($validNamespaces), 'page_is_redirect' => 0])
                ->caller(__METHOD__)
                ->fetchResultSet();

            $nsStats = [];
            foreach ($validNamespaces as $nsId => $nsName) {
                $nsStats[$nsId] = ['name' => $nsName, 'total' => 0, 'synced' => 0, 'out_of_date' => 0, 'missing' => 0];
            }

            foreach ($res as $row) {
                $nsId = (int) $row->page_namespace;
                if (!isset($nsStats[$nsId]))
                    continue;

                $titleObj = Title::makeTitle($nsId, $row->page_title);
                $prefixedTitle = $titleObj->getPrefixedText();
                $mwTouched = $row->page_touched;

                $nsStats[$nsId]['total']++;
                if (isset($mcpTimestamps[$prefixedTitle])) {
                    if ($mcpTimestamps[$prefixedTitle] >= $mwTouched) {
                        $nsStats[$nsId]['synced']++;
                    } else {
                        $nsStats[$nsId]['out_of_date']++;
                    }
                } else {
                    $nsStats[$nsId]['missing']++;
                }
            }

            $table = '<table class="mwassistant-table">';
            $table .= '<thead><tr>
                        <th>Namespace</th>
                        <th>Total Pages</th>
                        <th>Synced</th>
                        <th>Out of Date</th>
                        <th>Not Embedded</th>
                        <th>Action</th>
                       </tr></thead><tbody>';

            foreach ($nsStats as $nsId => $data) {
                $table .= '<tr>';
                $table .= '<td>' . htmlspecialchars($data['name']) . '</td>';
                $table .= '<td>' . $data['total'] . '</td>';

                // Pills
                $table .= '<td><span class="mwassistant-status-pill synced"><span class="label">Synced</span><span class="count">' . $data['synced'] . '</span></span></td>';
                $table .= '<td>' . ($data['out_of_date'] > 0 ? '<span class="mwassistant-status-pill out-of-date"><span class="label">Outdated</span><span class="count">' . $data['out_of_date'] . '</span></span>' : '0') . '</td>';
                $table .= '<td>' . ($data['missing'] > 0 ? '<span class="mwassistant-status-pill not-embedded"><span class="label">Missing</span><span class="count">' . $data['missing'] . '</span></span>' : '0') . '</td>';

                // Action
                $actionUrl = $this->getPageTitle()->getLocalURL();
                $btn = '<form method="post" action="' . htmlspecialchars($actionUrl) . '" style="margin:0;">';
                $btn .= Html::hidden('namespace_selector', $nsId);
                $btn .= '<button type="submit" name="batch_update" value="1" class="mwassistant-action-btn">Update</button>';
                $btn .= '</form>';

                $table .= '<td>' . $btn . '</td>';
                $table .= '</tr>';
            }
            $table .= '</tbody></table>';
            $output->addHTML($table);

            // Manual Update
            $output->addHTML('<h3 class="mwassistant-section-header">Single Page Update</h3>');
            $output->addHTML('<form method="post" action="' . htmlspecialchars($this->getPageTitle()->getLocalURL()) . '" class="mwassistant-single-update-card">');
            $output->addHTML('<label for="page-input" style="font-weight:600;">Page Title:</label>');
            $output->addHTML('<input type="text" name="page" id="page-input" class="mwassistant-input" placeholder="e.g. Main_Page" required>');
            $output->addHTML('<button type="submit" class="mwassistant-action-btn">Update Embedding</button>');
            $output->addHTML('</form>');

        } catch (\Exception $e) {
            $output->addHTML(Html::errorBox("Could not fetch embedding statistics: " . $e->getMessage()));
        }

        $output->addHTML('</div>'); // close dashboard
    }
}
