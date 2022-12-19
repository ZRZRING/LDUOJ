<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Client\StatusController;
use App\Http\Controllers\Controller;
use DOMDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\Input;

use App\Http\Controllers\UploadController;
use App\Jobs\CorrectSolutionsStatistics;
use App\Jobs\GenerateRejudgedCode;
use App\Jobs\Judger;

use const http\Client\Curl\AUTH_ANY;

class ProblemController extends Controller
{
    //管理员显示题目列表
    public function list()
    {
        $problems = DB::table('problems as p')
            ->leftJoin('users', 'creator', '=', 'users.id')
            ->select(
                'p.id',
                'title',
                'type',
                'source',
                'spj',
                'p.created_at',
                'hidden',
                'username as creator',
                'p.solved',
                'p.accepted',
                'p.submitted'
            )
            ->when(isset($_GET['pid']) && $_GET['pid'] != '', function ($q) {
                return $q->where('p.id', $_GET['pid']);
            })
            ->when(isset($_GET['title']) && $_GET['title'] != '', function ($q) {
                return $q->where('title', 'like', '%' . $_GET['title'] . '%');
            })
            ->when(isset($_GET['source']) && $_GET['source'] != '', function ($q) {
                return $q->where('source', 'like', '%' . $_GET['source'] . '%');
            })
            ->orderByDesc('p.id')
            ->paginate(isset($_GET['perPage']) ? $_GET['perPage'] : 100);
        return view('admin.problem.list', compact('problems'));
    }

    //管理员添加题目
    public function add(Request $request)
    {
        //提供加题界面
        if ($request->isMethod('get')) {
            $pageTitle = '添加题目';
            return view('admin.problem.edit', compact('pageTitle'));
        }
        //提交一条新题目
        if ($request->isMethod('post')) {
            $pid = DB::table('problems')->insertGetId(['creator' => Auth::id()]);
            return $this->update($request, $pid);
        }
    }

    //管理员修改题目
    public function update(Request $request, $id = -1)
    {
        //get提供修改界面
        if ($request->isMethod('get')) {

            $pageTitle = '修改题目';
            if ($id == -1) {
                if (isset($_GET['id'])) //用户手动输入了题号
                    return redirect(route('admin.problem.update_withId', $_GET['id']));
                return view('admin.problem.edit', compact('pageTitle'))->with('lack_id', true);
            } //询问要修改的题号

            $problem = DB::table('problems')->find($id);  // 提取出要修改的题目
            if ($problem == null)
                return view('message', ['msg' => '该题目不存在或操作有误!', 'success' => false, 'is_admin' => true]);

            $samples = read_problem_data($problem->id);
            //看看有没有特判文件
            $spj_exist = file_exists(testdata_path($problem->id . '/spj/spj.cpp'));
            return view('admin.problem.edit', compact('pageTitle', 'problem', 'samples', 'spj_exist'));
        }

        // 提交修改好的题目
        if ($request->isMethod('post')) {
            $problem = $request->input('problem');
            if (!isset($problem['spj'])) // 默认不特判
                $problem['spj'] = 0;

            $problem['updated_at'] = date('Y-m-d H:i:s');
            $update_ret = DB::table('problems')
                ->where('id', $id)
                ->update($problem);
            if (!$update_ret)
                return view('message', ['msg' => '您不是该题目的创建者，也不具备权限[admin.problem]，不能修改本题!', 'success' => false, 'is_admin' => true]);

            ///保存样例、spj
            $samp_ins = $request->input('sample_ins');
            $samp_outs = $request->input('sample_outs');
            save_problem_data($id, (array)$samp_ins, (array)$samp_outs, true); //保存样例

            $msg = sprintf(
                '题目<a href="%s" target="_blank">%d</a>修改成功！ <a href="%s">上传测试数据</a>',
                route('problem', $id),
                $id,
                route('admin.problem.test_data', 'pid=' . $id)
            );

            $spjFile = $request->file('spj_file');
            if ($spjFile != null && $spjFile->isValid()) {
                $spjFile->move(testdata_path($id . '/spj'), 'spj.cpp');  // 保存特判代码文件spj.cpp
                // $spj_compile = compile_cpp(testdata_path($id . '/spj/spj.cpp'), testdata_path($id . '/spj/spj')); //编译特判代码
                // $msg .= '<br><br>[ 特判程序编译信息 ]:<br>' . $spj_compile;
            }
            return view('message', ['msg' => $msg, 'success' => true, 'is_admin' => true]);
        }
    }

    public function get_spj($pid)
    {
        header('Content-type: text/plain; charset=UTF-8');
        header("Content-Disposition:attachement;filename=spj" . $pid . ".cpp"); //提示下载
        $filepath = testdata_path($pid . '/spj/spj.cpp');
        $spj_code = is_file($filepath) ? file_get_contents($filepath) : null;
        return $spj_code;
    }

    //管理员修改题目状态  0密封 or 1公开
    public function update_hidden(Request $request)
    {
        if ($request->isMethod('post')) {
            $pids = $request->input('pids') ?: [];
            $hidden = $request->input('hidden');
            return DB::table('problems')
                ->whereIn('id', $pids)
                ->update(['hidden' => $hidden]);
        }
        return 0;
    }


    //管理标签
    public function tags()
    {
        $tags = DB::table('tag_marks')
            ->join('users', 'user_id', '=', 'users.id')
            ->join('tag_pool', 'tag_id', '=', 'tag_pool.id')
            ->join('problems', 'problem_id', '=', 'problems.id')
            ->select('tag_marks.id', 'problem_id', 'title', 'username', 'nick', 'name', 'tag_marks.created_at')
            ->when(isset($_GET['pid']) && $_GET['pid'] != '', function ($q) {
                return $q->where('problem_id', $_GET['pid']);
            })
            ->when(isset($_GET['username']) && $_GET['username'] != '', function ($q) {
                return $q->where('username', $_GET['username']);
            })
            ->when(isset($_GET['tag_name']) && $_GET['tag_name'] != '', function ($q) {
                return $q->where('name', 'like', '%' . $_GET['tag_name'] . '%');
            })
            ->orderByDesc('id')
            ->paginate(isset($_GET['perPage']) ? $_GET['perPage'] : 20);
        return view('admin.problem.tags', compact('tags'));
    }
    public function tag_delete(Request $request)
    {
        $tids = $request->input('tids');
        return DB::table('tag_marks')->whereIn('id', $tids)->delete();
    }
    public function tag_pool()
    {
        $tag_pool = DB::table('tag_pool')
            ->select('id', 'name', 'hidden', 'created_at')
            ->when(isset($_GET['tag_name']) && $_GET['tag_name'] != '', function ($q) {
                return $q->where('name', 'like', '%' . $_GET['tag_name'] . '%');
            })
            ->orderByDesc('id')
            ->paginate(isset($_GET['perPage']) ? $_GET['perPage'] : 20);
        return view('admin.problem.tag_pool', compact('tag_pool'));
    }
    public function tag_pool_delete(Request $request)
    {
        $tids = $request->input('tids') ?: [];
        DB::table('tag_marks')->whereIn('tag_id', $tids)->delete(); //先删除用户提交的标记
        return DB::table('tag_pool')->whereIn('id', $tids)->delete();
    }
    public function tag_pool_hidden(Request $request)
    {
        $tids = $request->input('tids') ?: [];
        $hidden = $request->input('hidden');
        return DB::table('tag_pool')->whereIn('id', $tids)->update(['hidden' => $hidden]);
    }


    //测试数据管理页面
    public function test_data()
    {
        //读取数据文件
        $tests = [];
        if (isset($_GET['pid'])) {
            if (!DB::table('problems')->where('id', $_GET['pid'])->exists())
                return view('message', ['msg' => '题目' . $_GET['pid'] . '不存在', 'success' => false, 'is_admin' => true]);
            foreach (readAllFilesPath(testdata_path($_GET['pid'] . '/test')) as $filepath) {
                $name = pathinfo($filepath, PATHINFO_FILENAME);  //文件名
                $ext = pathinfo($filepath, PATHINFO_EXTENSION);    //拓展名
                $tests[] = ['index' => $name, 'filename' => $name . '.' . $ext, 'size' => filesize($filepath)];
            }
        }
        uasort($tests, function ($x, $y) {
            return $x['index'] > $y['index'];
        });
        return view('admin.problem.test_data', compact('tests'));
    }

    // ajax
    public function upload_data(Request $request)
    {
        $pid = $request->input('pid');
        $filename = $request->input('filename');
        $filename = str_replace('../', '', $filename);
        $filename = str_replace('/', '', $filename);
        $allowed_ext = ["in", "out", "ans", "txt"];
        if (!in_array(pathinfo($filename, PATHINFO_EXTENSION), $allowed_ext)) {
            //文件后缀名不在允许的范围内，不执行上传逻辑。
            return 1;
        }

        $uc = new UploadController;
        $isUploaded = $uc->upload($request, testdata_path($pid . '/test'), $filename);
        if (!$isUploaded) return 0;

        return 1;
    }

    //ajax
    public function get_data(Request $request)
    {
        $pid = $request->input('pid');
        $filename = $request->input('filename');
        $filename = str_replace('../', '', $filename);
        $filename = str_replace('/', '', $filename);
        $data = file_get_contents(testdata_path($pid . '/test/' . $filename));
        return json_encode($data);
    }

    //form
    public function update_data(Request $request)
    {
        $pid = $request->input('pid');
        $filename = $request->input('filename');
        $filename = str_replace('../', '', $filename);
        $filename = str_replace('/', '', $filename);
        $content = $request->input('content');
        file_put_contents(testdata_path($pid . '/test/' . $filename), str_replace(["\r\n", "\r", "\n"], PHP_EOL, $content));
        return back();
    }

    //ajax
    public function delete_data(Request $request)
    {
        $pid = $request->input('pid');
        $fnames = $request->input('fnames');
        foreach ($fnames as $filename)
            if (file_exists(testdata_path($pid . '/test/' . $filename)))
                unlink(testdata_path($pid . '/test/' . $filename));
        return 1;
    }

    public function import_export()
    {
        return view('admin.problem.import_export');
    }

    public function import(Request $request)
    {
        if (!$request->isMethod('post')) {
            return redirect(route('admin.problem.import_export'));
        }

        $uc = new UploadController;
        $isUploaded = $uc->upload($request, storage_path('app/xml_temp'), 'import_problems.xml');
        if (!$isUploaded) return 0;

        //读取xml->导入题库
        ini_set('memory_limit', '4096M'); //php单线程最大内存占用，默认128M不够用
        $xmlDoc = simplexml_load_file(storage_path('app/xml_temp/import_problems.xml'), null, LIBXML_NOCDATA | LIBXML_PARSEHUGE);
        $searchNodes = $xmlDoc->xpath("/*/item");
        $first_pid = null;
        foreach ($searchNodes as $node) {
            $problem = [
                'title'       => $node->title,
                'description' => $node->description,
                'input'       => $node->input,
                'output'      => $node->output,
                'hint'        => $node->hint,
                'source'      => $node->source,
                'spj'         => $node->spj ? 1 : 0,
                'time_limit'  => $node->time_limit * (strtolower($node->time_limit->attributes()->unit) == 's' ? 1000 : 1), //本oj用ms
                'memory_limit' => $node->memory_limit / (strtolower($node->memory_limit->attributes()->unit) == 'kb' ? 1024 : 1),
                'creator'     => Auth::id()
            ];
            //保存图片
            foreach ($node->img as $img) {
                $ext = pathinfo($img->src, PATHINFO_EXTENSION); //后缀
                $save_path = 'public/ckeditor/images/' . uniqid(date('Ymd/His_')) . '.' . $ext; //路径
                Storage::put($save_path, base64_decode($img->base64)); //保存
                $problem['description'] = str_replace($img->src, Storage::url($save_path), $problem['description']);
                $problem['input']      = str_replace($img->src, Storage::url($save_path), $problem['input']);
                $problem['output']     = str_replace($img->src, Storage::url($save_path), $problem['output']);
                $problem['hint']       = str_replace($img->src, Storage::url($save_path), $problem['hint']);
            }
            $pid = DB::table('problems')->insertGetId($problem);
            if (!$first_pid) $first_pid = $pid;
            //下面保存sample，test，spj
            $samp_inputs = (array)$node->children()->sample_input;
            $samp_outputs = (array)$node->children()->sample_output;
            $test_inputs = (array)$node->children()->test_input;
            $test_outputs = (array)$node->children()->test_output;
            save_problem_data($pid, $samp_inputs, $samp_outputs, true); //保存样例
            save_problem_data($pid, $test_inputs, $test_outputs, false); //保存测试数据
            if ($node->spj) {
                $dir = testdata_path($pid . '/spj'); // 特判文件夹
                if (!is_dir($dir))
                    mkdir($dir, 0777, true);  // 文件夹不存在则创建
                file_put_contents($dir . '/spj.cpp', $node->spj);  // 保存代码文件
                // compile_cpp($dir . '/spj.cpp', $dir . '/spj');  // 编译特判代码
            }
            foreach ($node->solution as $solu) {
                $language = $solu->attributes()->language;
                if ($language == 'Python') $language .= '3';  //本oj只支持python3
                $langs = [
                    'C' => 0,
                    'c' => 0,
                    'C++' => 1,
                    'c++' => 1,
                    'CPP' => 1,
                    'cpp' => 1,
                    'Java' => 2,
                    'java' => 2,
                    'Python3' => 3,
                ];
                $lang = ($langs[(string)($solu->attributes()->language)] ?? false);
                //保存提交记录
                if ($lang !== false) {
                    DB::table('solutions')->insert([
                        'problem_id'    => $pid,
                        'contest_id'    => -1,
                        'user_id'       => Auth::id(),
                        'result'        => 0,
                        'language'      => $lang,
                        'submit_time'   => date('Y-m-d H:i:s'),
                        'judge_type'    => 'oi', //acm,oi
                        'ip'            => $request->getClientIp(),
                        'code_length'   => strlen($solu),
                        'code'          => $solu,
                    ]);
                }
            }
        }
        Storage::deleteDirectory("xml_temp"); //删除已经没用的xml文件
        return $first_pid . ($first_pid < $pid ? '-' . $pid : '');
    }


    // 导出题目时，描述、题目数据等可能含有xml不支持的特殊字符，过滤掉
    private function filter_export_characters($str)
    {
        return preg_replace('/[\\x00-\\x08\\x0b-\\x0c\\x0e-\\x1f]/', '', $str);
    }
    public function export(Request $request)
    {
        if (!$request->isMethod('post')) {
            return redirect(route('admin.problem.import_export'));
        }
        ini_set('memory_limit', '2G'); //php单线程最大内存占用，默认128M不够用
        //处理题号,获取题目
        $problem_ids = $request->input('pids');
        foreach (explode(PHP_EOL, $problem_ids) as &$item) {
            $line = explode('-', $item);
            if (count($line) == 1) $pids[] = intval($line[0]);
            else foreach (range(intval($line[0]), intval(($line[1]))) as $i) $pids[] = $i;
        }
        $problems = DB::table("problems")->whereIn('id', $pids)->orderBy('id')->get();

        // 生成xml
        $dom = new DOMDocument("1.0", "UTF-8");
        $root = $dom->createElement('fps'); //为了兼容hustoj的fps标签
        // 作者信息 generator标签
        $generator = $dom->createElement('generator');
        $attr = $dom->createAttribute('name');
        $attr->appendChild($dom->createTextNode('LDUOJ'));
        $generator->appendChild($attr);
        $attr = $dom->createAttribute('url');
        $attr->appendChild($dom->createTextNode('https://github.com/iamwinter/LDUOnlineJudge'));
        $generator->appendChild($attr);
        $root->appendChild($generator);
        //遍历题目，生成xml字符串
        foreach ($problems as $problem) {
            $item = $dom->createElement('item');
            //title
            $title = $dom->createElement('title');
            $title->appendChild($dom->createCDATASection($this->filter_export_characters($problem->title)));
            $item->appendChild($title);
            //time_limit
            $unit = $dom->createAttribute('unit');
            $unit->appendChild($dom->createTextNode('ms'));
            $time_limit = $dom->createElement('time_limit');
            $time_limit->appendChild($unit);
            $time_limit->appendChild($dom->createCDATASection($problem->time_limit));
            $item->appendChild($time_limit);
            //memory_limit
            $unit = $dom->createAttribute('unit');
            $unit->appendChild($dom->createTextNode('mb'));
            $memory_limit = $dom->createElement('memory_limit');
            $memory_limit->appendChild($unit);
            $memory_limit->appendChild($dom->createCDATASection($problem->memory_limit));
            $item->appendChild($memory_limit);
            //description
            $description = $dom->createElement('description');
            $description->appendChild($dom->createCDATASection($this->filter_export_characters($problem->description)));
            $item->appendChild($description);
            //input
            $input = $dom->createElement('input');
            $input->appendChild($dom->createCDATASection($this->filter_export_characters($problem->input)));
            $item->appendChild($input);
            //output
            $output = $dom->createElement('output');
            $output->appendChild($dom->createCDATASection($this->filter_export_characters($problem->output)));
            $item->appendChild($output);
            //hint
            $hint = $dom->createElement('hint');
            $hint->appendChild($dom->createCDATASection($this->filter_export_characters($problem->hint)));
            $item->appendChild($hint);
            //source
            $source = $dom->createElement('source');
            $source->appendChild($dom->createCDATASection($this->filter_export_characters($problem->source)));
            $item->appendChild($source);

            //sample_input & sample_output
            foreach (read_problem_data($problem->id) as $sample) {
                $sample_input = $dom->createElement('sample_input');
                $sample_input->appendChild($dom->createCDATASection($this->filter_export_characters($sample[0])));
                $item->appendChild($sample_input);
                $sample_output = $dom->createElement('sample_output');
                $sample_output->appendChild($dom->createCDATASection($this->filter_export_characters($sample[1])));
                $item->appendChild($sample_output);
            }
            //test_input & test_output
            foreach (read_problem_data($problem->id, false) as $test) {
                $test_input = $dom->createElement('test_input');
                $test_input->appendChild($dom->createCDATASection($this->filter_export_characters($test[0])));
                $item->appendChild($test_input);
                $test_output = $dom->createElement('test_output');
                $test_output->appendChild($dom->createCDATASection($this->filter_export_characters($test[1])));
                $item->appendChild($test_output);
            }
            //spj language
            if ($problem->spj) {
                $filepath = testdata_path($problem->id . '/spj/spj.cpp');
                $spj_code = is_file($filepath) ? file_get_contents($filepath) : '';

                $cpp = $dom->createElement('spj');
                $attr = $dom->createAttribute('language');
                $attr->appendChild($dom->createTextNode('C++'));
                $cpp->appendChild($attr);
                $cpp->appendChild($dom->createCDATASection($spj_code));
                $item->appendChild($cpp);
            }
            //solution language
            $solutions = DB::table('solutions')
                ->select('language', 'code')
                ->whereRaw("id in(select min(id) from solutions where problem_id=? and result=4 group by language)", [$problem->id])
                ->get();
            foreach ($solutions as $sol) {
                $solution = $dom->createElement('solution');
                $attr = $dom->createAttribute('language');
                $attr->appendChild($dom->createTextNode(config('oj.judge_lang.' . $sol->language)));
                $solution->appendChild($attr);
                $solution->appendChild($dom->createCDATASection($sol->code));
                $item->appendChild($solution);
            }

            //img of description,input,output,hint
            preg_match_all('/<img.*?src=\"(.*?)\".*?>/i', $problem->description . $problem->input . $problem->output . $problem->hint, $matches);
            foreach ($matches[1] as $url) {
                $stor_path = str_replace("storage", "public", $url);
                if (Storage::exists($stor_path)) {
                    $img = $dom->createElement('img');
                    $src = $dom->createElement('src');
                    $src->appendChild($dom->createCDATASection($url));
                    $img->appendChild($src);
                    $base64 = $dom->createElement('base64');
                    $base64->appendChild($dom->createCDATASection(base64_encode(Storage::get($stor_path))));
                    $img->appendChild($base64);
                    $item->appendChild($img);
                }
            }
            //将该题插入root
            $root->appendChild($item);
        }
        $dom->appendChild($root);
        $dir = "problem_export_temp/";
        if (!Storage::exists($dir . Auth::id()))
            Storage::makeDirectory($dir . Auth::id());
        foreach (Storage::allFiles($dir) as $fpath) {  //删除24小时以上的文件
            if (time() - filemtime(storage_path('app/' . $fpath)) > 3600 * 24)
                Storage::delete($fpath);
        }
        //        $filename=str_replace("\r",',',str_replace("\n",',',str_replace("\r\n",',',$problem_ids))).".xml";
        $filename = str_replace(["\r\n", "\r", "\n"], ',', $problem_ids) . ".xml";
        $dom->save(storage_path("app/" . $dir . Auth::id() . '/' . $filename));
        return Storage::download($dir . Auth::id() . '/' . $filename);
    }
}
