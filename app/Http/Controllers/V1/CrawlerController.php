<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

class CrawlerController extends Controller
{
    /**
     * Fetch holidays with year selection and structured data
     */
    public function fetchContent(Request $request)
    {
        $year = $request->input('year', date('Y'));
        $url = "https://www.officialgazette.gov.ph/nationwide-holidays/{$year}/";

        try {

            $html = $this->fetchWithCloudflareBypass($url);

            $holidays = $this->extractHolidays($html, $year);

            return response()->json([
                'success' => true,
                'year' => $year,
                'url' => $url,
                'count' => count($holidays),
                'holidays' => $holidays,
            ]);
        } catch (\Exception $e) {
            Log::error('Crawler error', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract structured holiday data from HTML based on Section Headers
     */
    private function extractHolidays(string $html): array
    {
        if (stripos($html, 'cloudflare') !== false && stripos($html, 'blocked') !== false) {
            throw new \Exception('Blocked by Cloudflare protection');
        }

        $crawler = new Crawler($html);
        $holidays = [];

        $sectionMap = [
            'Regular Holiday' => 'Regular Holiday',
            'Special (Non-Working)' => 'Special Non-Working Holiday',
            'Special (Working)' => 'Special Working Holiday',
        ];

        $crawler->filter('h4')->each(function (Crawler $headerNode) use (&$holidays, $sectionMap) {
            $headerText = $headerNode->text();
            $currentType = null;

            // Determine which type this header represents
            foreach ($sectionMap as $key => $value) {
                if (stripos($headerText, $key) !== false) {
                    $currentType = $value;
                    break;
                }
            }

            if ($currentType) {

                $parentDiv = $headerNode->closest('div');

                $parentDiv->filter('table tr')->each(function (Crawler $row) use (&$holidays, $currentType) {

                    if ($row->filter('.holiday-what')->count() > 0) {
                        try {

                            $name = trim($row->filter('.holiday-what')->text());

                            $date = null;

                            $dateNodeAbbr = $row->filter('.holidate abbr');
                            if ($dateNodeAbbr->count() > 0) {
                                $date = $dateNodeAbbr->attr('title') ?: trim($dateNodeAbbr->text());
                            }
                            // Fallback to standard text
                            else {
                                $dateNode = $row->filter('.holidate');
                                if ($dateNode->count() > 0) {
                                    $date = trim($dateNode->text());
                                }
                            }

                            if ($name && $date) {
                                $holidays[] = [
                                    'date' => $date,
                                    'name' => $name,
                                    'type' => $currentType,
                                ];
                            }
                        } catch (\Exception $e) {
                            Log::warning('Error parsing specific holiday row', ['error' => $e->getMessage()]);
                        }
                    }
                });
            }
        });

        return $holidays;
    }

    private function fetchWithCloudflareBypass(string $url): string
    {
        return Browsershot::url($url)
            ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
            ->setOption('args', [
                '--disable-blink-features=AutomationControlled',
                '--no-sandbox',
                '--disable-setuid-sandbox',
            ])
            ->waitUntilNetworkIdle()
            ->delay(3000)
            ->timeout(120)
            ->bodyHtml();
    }

    public function crawlMultipleYears(Request $request)
    {
        $startYear = $request->input('start_year', date('Y') - 1);
        $endYear = $request->input('end_year', date('Y') + 1);
        $delay = $request->input('delay', 2000);

        try {
            $allYears = [];

            for ($year = $startYear; $year <= $endYear; $year++) {
                Log::info('Crawling year', ['year' => $year]);

                $url = "https://www.officialgazette.gov.ph/nationwide-holidays/{$year}/";
                $html = $this->fetchWithCloudflareBypass($url);
                $holidays = $this->extractHolidays($html, $year);

                $allYears[] = [
                    'year' => $year,
                    'count' => count($holidays),
                    'holidays' => $holidays,
                ];

                // Delay between requests
                if ($year < $endYear) {
                    usleep($delay * 1000);
                }
            }

            return response()->json([
                'success' => true,
                'start_year' => $startYear,
                'end_year' => $endYear,
                'total_years' => count($allYears),
                'data' => $allYears,
            ]);
        } catch (\Exception $e) {
            Log::error('Multi-year crawl error', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function debug(Request $request)
    {
        $year = $request->input('year', date('Y'));
        $url = "https://www.officialgazette.gov.ph/nationwide-holidays/{$year}/";

        try {
            $html = $this->fetchWithCloudflareBypass($url);

            $isBlocked = stripos($html, 'cloudflare') !== false && stripos($html, 'blocked') !== false;

            $crawler = new Crawler($html);

            return response()->json([
                'success' => true,
                'url' => $url,
                'year' => $year,
                'cloudflare_blocked' => $isBlocked,
                'html_length' => strlen($html),
                'counts' => [
                    'div_holiday_what' => $crawler->filter('div.holiday-what')->count(),
                    'div_holidate' => $crawler->filter('div.holidate')->count(),
                    'td_holiday_item' => $crawler->filter('td.holiday-item')->count(),
                ],
                'html_preview' => substr($html, 0, 1000),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
