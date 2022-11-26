<?php

namespace App\View\Components\Solution;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\Component;

class LineChart extends Component
{
    public $x, $submitted, $accepted, $solved;

    public function __construct($defaultPast = '30d', $userId = null, $contestId = null, $groupId = null)
    {
        // 筛选的起始时间
        $sub_sql = [
            '300i' => [
                'groupby' => DB::raw("DATE_FORMAT(`submit_time`, '%Y-%m-%d %H:%i:00') AS groupby"),
                'start_date' => date("Y-m-d H:i:00", strtotime("-300 minute")),
                'cache_seconds' => 30, // 缓存30秒
            ],
            '24h' => [
                'groupby' => DB::raw("DATE_FORMAT(`submit_time`, '%Y-%m-%d %H:00') AS groupby"),
                'start_date' => date("Y-m-d H:00:00", strtotime("-24 hour")),
                'cache_seconds' => 600, // 缓存10分钟
            ],
            '30d' => [
                'groupby' => DB::raw("DATE_FORMAT(`submit_time`, '%Y-%m-%d') AS groupby"),
                'start_date' => date("Y-m-d 00:00:00", strtotime("-30 day")),
                'cache_seconds' => 3600, // 缓存1小时
            ],
            '180d' => [
                'groupby' => DB::raw("DATE_FORMAT(`submit_time`, '%Y-%m-%d') AS groupby"),
                'start_date' => date("Y-m-d 00:00:00", strtotime("-180 day")),
                'cache_seconds' => 3600 * 12, // 缓存12小时
            ],
            '12m' => [
                'groupby' => DB::raw("DATE_FORMAT(`submit_time`, '%Y-%m') AS groupby"),
                'start_date' => date("Y-m-d 00:00:00", strtotime("-12 month")),
                'cache_seconds' => 3600 * 24, // 缓存1天
            ],
        ];
        if (!isset($_GET['past']))
            $_GET['past'] = $defaultPast;
        $option = $sub_sql[$_GET['past']];

        // 查询数据库
        $solutions = Cache::remember(
            sprintf('solution:line-chart:%s,%s,%s,%s', $_GET['past'], $userId, $contestId, $groupId),
            $option['cache_seconds'],
            function () use ($userId, $contestId, $groupId, $option) {
                return DB::table('solutions as s')
                    ->select([
                        DB::raw('count(*) as submitted'),
                        DB::raw('count(result=4 or null) as accepted'),
                        DB::raw('count(distinct (problem_id * 10 + (result=4 or null))) as solved'),
                        $option['groupby']
                    ])
                    ->when($userId !== null, function ($q) use ($userId) {
                        return $q->where('user_id', $userId);
                    })
                    ->when($contestId !== null, function ($q) use ($contestId) {
                        return $q->where('contest_id', $contestId);
                    })
                    ->when($groupId !== null, function ($q) use ($groupId) {
                        return $q->join('group_contests as gc', 'gc.contest_id', 's.contest_id')
                            ->where('group_id', $groupId);
                    })
                    ->where('submit_time', '>', $option['start_date'])
                    ->groupBy('groupby')
                    ->get()->toArray();
            }
        );

        // 汇总数据
        $this->x = array_map(function ($v) {
            return $v->groupby;
        }, $solutions);
        $this->submitted = array_map(function ($v) {
            return $v->submitted;
        }, $solutions);
        $this->accepted = array_map(function ($v) {
            return $v->accepted;
        }, $solutions);
        $this->solved = array_map(function ($v) {
            return $v->solved;
        }, $solutions);
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return \Illuminate\Contracts\View\View|\Closure|string
     */
    public function render()
    {
        return view('components.solution.line-chart');
    }
}