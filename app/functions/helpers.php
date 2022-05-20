<?php

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;


// 获取用户真实ip（参考https://blog.csdn.net/m0_46266407/article/details/107222142）
function get_client_real_ip()
{
    $clientip = '';
    if (getenv('HTTP_CLIENT_IP') && strcasecmp(getenv('HTTP_CLIENT_IP'), 'unknown')) {
        $clientip = getenv('HTTP_CLIENT_IP');
    } elseif (getenv('HTTP_X_FORWARDED_FOR') && strcasecmp(getenv('HTTP_X_FORWARDED_FOR'), 'unknown')) {
        $clientip = getenv('HTTP_X_FORWARDED_FOR');
    } elseif (getenv('REMOTE_ADDR') && strcasecmp(getenv('REMOTE_ADDR'), 'unknown')) {
        $clientip = getenv('REMOTE_ADDR');
    } elseif (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] && strcasecmp($_SERVER['REMOTE_ADDR'], 'unknown')) {
        $clientip = $_SERVER['REMOTE_ADDR'];
    }
    preg_match("/[\d.]{7,15}/", $clientip, $clientipmatches);
    $clientip = $clientipmatches[0] ? $clientipmatches[0] : 'unknown';
    return $clientip;
}

function starts_with($str, $prefix)
{
    return substr($str, 0, strlen($prefix)) == $prefix;
}

//查询用户权限: 查询user是否具有power权限
function privilege($power, $user = null)
{
    // 默认为当前用户
    if ($user == null)
        $user = Auth::user();
    // 无效的user
    if (!$user || !isset($user->id))
        return false;
    // 验证权限代号的有效性
    if (!array_key_exists($power, config('oj.authority')))
        abort(502, '[系统错误] 不存在的权限：' . $power);
    /*
    权限说明：
        admin涵盖所有权限
        admin.home为进入后台的权限
        admin.problem包含admin.problem.*所有权限，其它类同
        只要数据库中含有$power的前缀，则说明具有当前权限.
    */
    // 从数据库中查询出该用户已有权限
    $powers = DB::table('privileges')->where('user_id', $user->id)->pluck('authority');
    foreach ($powers as $p) {
        // 数据库中具有上层权限，验证通过
        if (starts_with($power, $p))
            return true;
        // 如果数据库中含有teacher，则查询以下权限时均通过
        if (
            $p == 'teacher' &&
            (starts_with($power, 'admin.home') ||
                starts_with($power, 'admin.problem') ||
                starts_with($power, 'admin.contest') ||
                starts_with($power, 'admin.group'))
        )
            return true;
    }
    return false;
}

// 获取测试数据保存路径
function testdata_path($path = null): string
{
    $testdata_path = config('app.JG_DATA_DIR');
    if ($testdata_path[0] != '/')  # 如果是相对路径，那就加上当前项目的绝对路径
        $testdata_path = base_path($testdata_path);
    if ($path != null)
        $testdata_path .= '/' . $path;
    return $testdata_path;
}

//读取一个文件夹下所有文件，返回路径列表
function readAllFilesPath($dir_path): array
{
    clearstatcache(); //清除缓存
    $files = [];
    if (is_dir($dir_path)) {
        foreach (scandir($dir_path) as $item) {
            $real_item = $dir_path . '/' . $item;
            if (is_file($real_item)) {
                $files[] = $real_item;
            }
        }
    }
    return $files;
}

/**
 * 读取样例/测试文件
 * @param $problem_id
 * @param bool $from_sample
 * @return array  返回二维字符串数组，第一维[test0,test1,...]，第二维[.in, .out]
 */
function read_problem_data($problem_id, $from_sample = true): array
{
    $samples = [];
    $dir = testdata_path($problem_id . '/' . ($from_sample ? 'sample' : 'test'));
    foreach (readAllFilesPath($dir) as $item) {
        $name = pathinfo($item, PATHINFO_FILENAME);  //文件名
        $ext = pathinfo($item, PATHINFO_EXTENSION);    //拓展名
        if (!isset($samples[$name])) //发现新样本
            $samples[$name] = ['', ''];
        if ($ext === 'in')
            $samples[$name][0] = file_get_contents($item);
        if ($ext === 'out' || $ext === 'ans')
            $samples[$name][1] = file_get_contents($item);
    }
    return $samples;
}

/**
 * 保存样例/测试到文件
 * @param $problem_id
 * @param $ins
 * @param $outs
 * @param bool $from_sample
 */
function save_problem_data($problem_id, $ins, $outs, $from_sample = true)
{
    $dir = testdata_path($problem_id . '/' . ($from_sample ? 'sample' : 'test')); // 测试数据文件夹
    foreach (readAllFilesPath($dir) as $item)
        unlink($item); //删除原有文件
    if (!is_dir($dir))
        mkdir($dir, 0777, true);  // 文件夹不存在则创建
    foreach ($ins as $i => $in)
        file_put_contents(sprintf('%s/%s.in', $dir, $i), $in);
    foreach ($outs as $i => $out)
        file_put_contents(sprintf('%s/%s.out', $dir, $i), $out);
}

/**
 * @param $cpp_path
 * @param $out_path
 * @return string
 *  编译c++文件；该功能在后期开发中即将废弃，应当通过linux终端编译，或判题端编译
 */
function compile_cpp($cpp_path, $out_path): string
{
    $cmd = sprintf("sudo g++ %s -o %s -lm -std=c++17 2>&1", $cpp_path, $out_path);
    $out[] = $cmd;
    exec($cmd, $out);
    if (count($out) == 1)
        $out[] = "Compiled successfully!";
    else
        $out[] = "Compilation failed!";
    return implode('<br>', $out);
}

/**
 * 获取本题的特判代码
 * @param $problem_id
 * @return string
 */
function get_spj_code($problem_id): ?string
{
    $filepath = testdata_path($problem_id . '/spj/spj.cpp');
    if (is_file($filepath))
        return file_get_contents($filepath);
    return null;
}

//将一个数字题号转为大写字母 A~Z(0~25), 27, 28, 29, ...
function index2ch(int $index)
{
    if ($index < 26)
        return chr($index + 65);
    return $index + 1; //Z的下一题是27题
}

//从txt文件读取的内容转码
function autoiconv($text, $type = "gb2312//ignore")
{
    define('UTF32_BIG_ENDIAN_BOM', chr(0x00) . chr(0x00) . chr(0xFE) . chr(0xFF));
    define('UTF32_LITTLE_ENDIAN_BOM', chr(0xFF) . chr(0xFE) . chr(0x00) . chr(0x00));
    define('UTF16_BIG_ENDIAN_BOM', chr(0xFE) . chr(0xFF));
    define('UTF16_LITTLE_ENDIAN_BOM', chr(0xFF) . chr(0xFE));
    define('UTF8_BOM', chr(0xEF) . chr(0xBB) . chr(0xBF));
    $first2 = substr($text, 0, 2);
    $first3 = substr($text, 0, 3);
    $first4 = substr($text, 0, 3);
    $encodType = "";
    if ($first3 == UTF8_BOM)
        $encodType = 'UTF-8 BOM';
    else if ($first4 == UTF32_BIG_ENDIAN_BOM)
        $encodType = 'UTF-32BE';
    else if ($first4 == UTF32_LITTLE_ENDIAN_BOM)
        $encodType = 'UTF-32LE';
    else if ($first2 == UTF16_BIG_ENDIAN_BOM)
        $encodType = 'UTF-16BE';
    else if ($first2 == UTF16_LITTLE_ENDIAN_BOM)
        $encodType = 'UTF-16LE';
    //下面的判断主要还是判断ANSI编码的·
    if ($encodType == '') { //即默认创建的txt文本-ANSI编码的
        //        $content = mb_convert_encoding($text,"UTF-8","auto");
        $content = iconv("GBK", "UTF-8//ignore", $text);
    } else if ($encodType == 'UTF-8 BOM') { //本来就是UTF-8不用转换
        $content = $text;
    } else { //其他的格式都转化为UTF-8就可以了
        $content = iconv($encodType, "UTF-8", $text);
    }
    return $content;
}
