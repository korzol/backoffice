<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use App\Entity\NavigationTimings;
use App\Entity\NavigationTimingsUrls;

use App\BasicRum\Report;
use App\BasicRum\DiagramBuilder;
use App\BasicRum\DiagramOrchestrator;
use App\BasicRum\CollaboratorsAggregator;

class DashboardController extends AbstractController
{
    /**
     * @Route("/dashboard", name="dashboard")
     */
    public function index()
    {
        return $this->render('waterfall.html.twig',
            [
//                'popular_pages_performance' => $this->getPagesPerformanceData($this->tenMostPopularVisitedPages()),
//                'device_samples'            => json_encode($this->deviceSamples()),
                'last_page_views'           => $this->lastPageViewsListHTML()
            ]
        );
    }

    private function getPagesPerformanceData(array $data)
    {
        $pageViewsPerformance = [];

        $pageNumber = 1;

        $allVisitsCount = $this->countViewsInPeriod('-1 day', '-8 days')['visitsCount'];

        foreach ($data as $urlId => $count) {
            /** @var \App\Entity\NavigationTimingsUrls $resourceTimingUrl */
            $navigationTimingUrl = $this->getDoctrine()
                ->getRepository(NavigationTimingsUrls::class)
                ->findOneBy(['id' => $urlId]);

            $metrics = [
                'first_byte',
                'first_paint'
            ];

            $recentSamples = $this->periodForUrl($navigationTimingUrl->getUrl(), '-1 day', '-8 days', $metrics);
            $oldSamples    = $this->periodForUrl($navigationTimingUrl->getUrl(), '-9 day', '-16 days', $metrics);

            //@todo: Move this to some decorator logic or use TWIG if possible
            $firstByteDiff  = $oldSamples['first_byte']['median'] - $recentSamples['first_byte']['median'];
            $firstPaintDiff = $oldSamples['first_paint']['median'] - $recentSamples['first_paint']['median'];

            $firstByteDiff  = number_format($firstByteDiff / 1000, 2);
            $firstPaintDiff = number_format($firstPaintDiff / 1000, 2);

            $firstByteDiffStyle  = ($firstByteDiff  === '-0.00' || $firstByteDiff  === '0.00') ? '' : ($firstByteDiff > 0 ? 'color: red;' : 'color: green;');
            $firstPaintDiffStyle = ($firstPaintDiff === '-0.00' || $firstPaintDiff === '0.00') ? '' : ($firstPaintDiff > 0 ? 'color: red;' : 'color: green;');

            $firstByteDiff  = ($firstByteDiff  == '-0.00') ? '0.00' : ($firstByteDiff > 0 ? '+ ' . $firstByteDiff : $firstByteDiff);
            $firstPaintDiff = ($firstPaintDiff == '-0.00') ? '0.00' : ($firstPaintDiff > 0 ? '+ ' . $firstPaintDiff : $firstPaintDiff);

            $urlParsed = parse_url($navigationTimingUrl->getUrl());

            $pageViewsPerformance[] = [
                'number'                 => $pageNumber++,
                'page_views'             => number_format(($count / $allVisitsCount) * 100, 2) . ' %',
                'first_byte_median'      => number_format($recentSamples['first_byte']['median'] / 1000, 2) .  ' s',
                'first_byte_diff'        => $firstByteDiff .  ' s',
                'first_byte_diff_style'  => $firstByteDiffStyle,
                'first_paint_median'     => number_format($recentSamples['first_paint']['median']  / 1000, 2) .  ' s',
                'first_paint_diff'       => $firstPaintDiff .  ' s',
                'first_paint_diff_style' => $firstPaintDiffStyle .  ' s',
                'url'                    => substr($urlParsed['path'], 0, 27)
            ];
        }

        return $pageViewsPerformance;
    }

    public function periodForUrl(string $url, string $start, string $end, array $metrics)
    {
        $today = new \DateTime($start);
        $past  = new \DateTime($end);

        $period = [
            'current_period_from_date' => $past->format('Y-m-d'),
            'current_period_to_date'   => $today->format('Y-m-d'),
        ];

        $samples = [];

        // Combine metric in array
        foreach ($metrics as $metric) {
            $data = [
                'period'      => $period,
                'perf_metric' => $metric,
                'filters'     => [
                    'url' => [
                        'search_value' => $url,
                        'condition'    => 'is'
                    ]
                ]
            ];

            $report = new Report($this->getDoctrine());

            $diagramBuilder = new DiagramBuilder($report);

            $samples[$metric] = $diagramBuilder->build($data);
        }

        return $samples;
    }

    /**
     * @return array
     */
    private function tenMostPopularVisitedPages()
    {
        $popularPages = [];

        $repository = $this->getDoctrine()->getRepository(NavigationTimings::class);

        $days = 7;

        for ($d = 1; $d <= $days; $d++) {
            $p = $d + 1;
            $today = new \DateTime("-{$d} days");
            $past  = new \DateTime("-{$p} days");

            /** @var \Doctrine\ORM\QueryBuilder $queryBuilder */
            $queryBuilder = $repository->createQueryBuilder('nt');

            $queryBuilder
                ->select(['count(nt.urlId) as visitsCount', 'nt.urlId'])
                ->where("nt.createdAt BETWEEN '" . $past->format('Y-m-d') . " 00:00:00' AND '" . $today->format('Y-m-d') . " 00:00:00'")
                ->groupBy('nt.urlId')
                ->orderBy('count(nt.urlId)', 'DESC')
                ->setMaxResults(20)
                ->getQuery();


            $navigationTimings = $queryBuilder->getQuery()
                ->getResult();

            foreach ($navigationTimings as $nav) {
                $popularPages[$nav['urlId']] = isset($popularPages[$nav['urlId']]) ? $popularPages[$nav['urlId']] + $nav['visitsCount'] : $nav['visitsCount'];
            }
        }

        arsort($popularPages);

        return array_slice($popularPages, 0, 10, true);
    }

    private function countViewsInPeriod($start, $end)
    {
        $recent = new \DateTime($start);
        $past   = new \DateTime($end);

        $repository = $this->getDoctrine()->getRepository(NavigationTimings::class);

        /** @var \Doctrine\ORM\QueryBuilder $queryBuilder */
        $queryBuilder = $repository->createQueryBuilder('nt');

        $queryBuilder
            ->select(['count(nt.pageViewId) as visitsCount'])
            ->where("nt.createdAt BETWEEN '" . $past->format('Y-m-d') . " 00:00:00' AND '" . $recent->format('Y-m-d') . " 00:00:00'")
            ->getQuery();

        return $queryBuilder->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array
     */
    private function deviceSamples()
    {
        $devices = [
            'Desktop',
            'Tablet',
            'Mobile'
        ];

        $colors = [
            'Desktop' => 'rgb(31, 119, 180)',
            'Tablet'  => 'rgb(255, 127, 14)',
            'Mobile'  => 'rgb(44, 160, 44)'
        ];

        $today = new \DateTime(('-1 day'));
        $past  = new \DateTime('-2 weeks');

        $period = [
            'current_period_from_date' => $past->format('Y-m-d'),
            'current_period_to_date'   => $today->format('Y-m-d'),
        ];

        $samples = [];

        foreach ($devices as $device) {
            $data = [
                'period'      => $period,
                'perf_metric' => 'first_byte',
                'filters'     => [
                    'device_type' => [
                        'search_value' => $device,
                        'condition'    => 'is'
                    ]
                ]
            ];

            $report = new Report($this->getDoctrine());

            $diagramBuilder = new DiagramBuilder($report);

            $samples[$device] = $diagramBuilder->count($data);
        }

        $deviceDiagrams = [];

        foreach ($samples as $device => $data) {
            $deviceDiagrams[] = [
                'x'          => array_keys($data),
                'y'          => array_values($data),
                'name'       => $device,
                'stackgroup' => 'device',
                'line'       => [
                    'color'  => $colors[$device]
                ]
            ];
        }

        return $deviceDiagrams;
    }

    private function lastPageViewsListHTML()
    {
        $collaboratorsAggregator = new CollaboratorsAggregator();

        $requirementsArr = [
            'filters' => [
                'device_type' => [
                    'condition'    => 'is',
                    'search_value' => 'mobile'
                ],
//                'os_name' => [
//                    'condition'    => 'is',
//                    'search_value' => 'iOS'
//                ],
//                'device_manufacturer' => [
//                    'condition'    => 'is',
//                    'search_value' => 'Huawei'
//                ],
//                'browser_name' => [
//                    'condition'    => 'is',
//                    'search_value' => 'Chrome Dev'
//                ],
//                'url' => [
//                    'condition'    => 'contains',
////                    'search_value' => 'https://www.hundeland.de/marken/marken-hund/wolfsblut',
//                    'search_value' => 'https://www.hundeland.de/catalog/product/view/id'
//                ]
            ],
            'periods' => [
                [
                    'from_date' => '02/01/2019',
                    'to_date'   => '02/02/2019'
                ]
            ],
            'technical_metrics' => [
                'time_to_first_paint' => 1
            ],
            'business_metrics'  => [
                'bounce_rate'       => 1,
                'stay_on_page_time' => 1
            ]
        ];

        $collaboratorsAggregator->fillRequirements($requirementsArr);

        $diagramOrchestrator = new DiagramOrchestrator($collaboratorsAggregator->getCollaborators(), $this->getDoctrine());

        $res = $diagramOrchestrator->process();

        $sessionsCount = 0;
        $bouncesCount = 0;
        $convertedSessions = 0;

        $groupMultiplier = 100;
        $upperLimit = 5000;

        $firstPaintArr = [];
        $allFirstPaintArr = [];
        $bouncesGroup  = [];
        $bouncedPageViews = [];

        // Init the groups/buckets
        for($i = $groupMultiplier; $i <= $upperLimit; $i += $groupMultiplier) {
            $allFirstPaintArr[$i] = 0;
        }

        for($i = $groupMultiplier; $i <= $upperLimit; $i += $groupMultiplier) {
            $firstPaintArr[$i] = 0;
            $allFirstPaintArr[$i] = 0;
            if ($i >= 250 && $i <= $upperLimit) {
                $bouncesGroup[$i] = 0;
            }
        }

        foreach ($res[0] as $day) {
            foreach ($day as $row) {
                $ttfp  = $row['firstPaint'];

                $paintGroup = $groupMultiplier * (int) ($ttfp / $groupMultiplier);

                if ($upperLimit >= $paintGroup && $paintGroup > 0) {
                    $allFirstPaintArr[$paintGroup]++;
                }

                if ($upperLimit >= $paintGroup && $paintGroup > 0) {

                    if ($paintGroup >= 250 && $paintGroup  <= $upperLimit) {
                        $firstPaintArr[$paintGroup]++;
                        $sessionsCount++;

                        if ($row['pageViewsCount'] == 1 && $row['stayOnPageTime'] == 0) {
//                            if ($paintGroup >= 1200 && $paintGroup <= 2200) {
                                $bouncedPageViews[] = $row['pageViewId'];
//                            }

                            $bouncesCount++;
                            $bouncesGroup[$paintGroup]++;
                        }
                    }
                }
            }
        }

        //print_r($bouncedPageViews);

        $repository = $this->getDoctrine()
            ->getRepository(NavigationTimings::class);
        $query = $repository->createQueryBuilder('nt')
            ->orderBy('nt.pageViewId', 'DESC')
            ->where('nt.pageViewId IN (' . implode(',', $bouncedPageViews) . ')')
            ->getQuery();

        $navigationTimings = $query->getResult();

        $navTimingsFiltered = [];

        foreach ($navigationTimings as $navTiming) {
            if ($navTiming->getFirstContentfulPaint() > 0) {
                $navTimingsFiltered[] = $navTiming;
            }
        }

        return $this->get('twig')->render(
            'diagrams/waterfalls_list.html.twig',
            [
                'page_views' => $navTimingsFiltered
            ]
        );
    }

}
