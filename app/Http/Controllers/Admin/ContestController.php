<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use function PHPUnit\Framework\StaticAnalysis\HappyPath\AssertIsArray\consume;

class ContestController extends Controller
{
    public function list(){
        $contests=DB::table('contests')
            ->leftJoin('users','users.id','=','user_id')
            ->select('contests.*','username')
            ->orderBy('id')->paginate();
        return view('admin.contest.list',compact('contests'));
    }

    public function add(Request $request){
        if($request->isMethod('get')){
            $pageTitle='创建竞赛';
            return view('admin.contest.edit',compact('pageTitle'));
        }
        if($request->isMethod('post')){
            $cid=DB::table('contests')->insertGetId(['user_id'=>Auth::id()]);
            $this->update($request,$cid);
            $msg=sprintf('成功创建竞赛：<a href="%s" target="_blank">%d</a>',route('contest.home',$cid),$cid);
            return view('admin.success',compact('msg'));
        }
    }

    public function update(Request $request,$id){
        if (!Auth::user()->privilege('admin')&&Auth::id()!=DB::table('contests')->where('id',$id)->value('id'))
            return view('admin.fail',['msg'=>'权限不足！您不是这场比赛的创建者']);

        if($request->isMethod('get')){
            $contest=DB::table('contests')->find($id);
            $unames=DB::table('contest_users')
                ->leftJoin('users','users.id','=','user_id')
                ->where('contest_id',$id)->pluck('username');
            $pids=DB::table('contest_problems')->where('contest_id',$id)
                ->orderBy('index')->pluck('problem_id');
            $files=[];
            foreach(Storage::allFiles('public/contest/files/'.$id) as &$item){
                $files[]=array_slice(explode('/',$item),-1,1)[0];
            }
            $pageTitle='修改竞赛';
            return view('admin.contest.edit',compact('pageTitle','contest','unames','pids','files'));
        }
        if($request->isMethod('post')){
            $contest=$request->input('contest');
            $problem_ids=$request->input('problems');
            $c_users=$request->input('contest_users'); //指定用户
            $files=$request->file('files')?:[];

            //数据格式处理
            foreach (explode(PHP_EOL,$problem_ids) as &$item){
                $line=explode('-',$item);
                if(count($line)==1) $pids[]=intval($line[0]);
                else foreach (range(intval($line[0]),intval(($line[1]))) as $i) $pids[]=$i;
            }
            $pids=array_filter($pids,function ($val){return DB::table('problems')->where('id',$val)->exists();});//过滤
            $contest['start_time']=str_replace('T',' ',$contest['start_time']);
            $contest['end_time']  =str_replace('T',' ',$contest['end_time']);
            if($contest['access']!='password')unset($contest['password']);

            //数据库
            DB::table('contests')->where('id',$id)->update($contest);
            DB::table('contest_problems')->where('contest_id',$id)->whereNotIn('problem_id',$pids)->delete();//舍弃的
            foreach ($pids as $i=>$pid){
                DB::table('contest_problems')
                    ->updateOrInsert(['contest_id'=>$id,'problem_id'=>$pid],['index'=>1+$i]);
            }
            if($contest['access']=='private'){
                $uids=DB::table('users')->whereIn('username',explode(PHP_EOL,$c_users))->pluck('id');
                DB::table('contest_users')->where('contest_id',$id)->whereNotIn('user_id',$uids)->delete();
                foreach($uids as &$uid)
                    DB::table('contest_users')->insertOrIgnore(['contest_id'=>$id,'user_id'=>$uid]);
            }
            if($contest['access']=='password'){
                DB::table('contest_users')->where('contest_id',$id)->delete();
            }

            //附件
            foreach ($files as $file) {     //保存附件
                $file->move(storage_path('app/public/contest/files/'.$id),$file->getClientOriginalName());//保存附件
            }
            $msg=sprintf('成功更新竞赛：<a href="%s" target="_blank">%d</a>',route('contest.home',$id),$id);
            return view('admin.success',compact('msg'));
        }
    }

    public function upload_image(Request $request){
        $image=$request->file('upload');
        $fname=uniqid(date('Ymd_His_')).'.'.$image->getClientOriginalExtension();
        $image->move(storage_path('app/public/contest/images'),$fname);
        return json_encode(['uploaded'=>true,'url'=> Storage::url('public/contest/images/'.$fname)]);
    }

    public function delete(Request $request){
        $cids=$request->input('cids')?:[];
        if (Auth::user()->privilege('admin')) //超管，直接进行
            $ret=DB::table('contests')->whereIn('id',$cids)->delete();
        else
            $ret=DB::table('contests')->whereIn('id',$cids)->where('user_id',Auth::id())->delete();//创建者
        if($ret>0){
            foreach ($cids as $cid) {
                Storage::deleteDirectory('public/contest/files/'.$cid); //删除附件
            }
        }
        return $ret;
    }

    public function delete_file(Request $request,$id){  //$id:竞赛id
        $filename=$request->input('filename');
        if(Storage::exists('public/contest/files/'.$id.'/'.$filename))
            return Storage::delete('public/contest/files/'.$id.'/'.$filename)?1:0;
        return 0;
    }

    public function update_hidden(Request $request){
        $cids=$request->input('cids')?:[];
        $hidden=$request->input('hidden');
        if (Auth::user()->privilege('admin')) //超管，直接进行
            return DB::table('contests')->whereIn('id',$cids)->update(['hidden'=>$hidden]);
        return DB::table('contests')->whereIn('id',$cids)
            ->where('user_id',Auth::id())->update(['hidden'=>$hidden]);
    }
}