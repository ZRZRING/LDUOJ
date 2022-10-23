# 判题结果

配置文件[judge_result.php](https://github.com/winterant/OnlineJudge/blob/master/config/oj/judge_result.php)

```php
<?php
return [
    0 => 'Waiting',
    1 => 'Queueing',
    2 => 'Compiling',
    3 => 'Running',
    4 => 'Accepted',
    5 => 'Presentation Error',
    6 => 'Wrong Answer',
    7 => 'Time Limit Exceeded',
    8 => 'Memory Limit Exceeded',
    9 => 'Output Limit Exceeded',
    10=> 'Runtime Error',
    11=> 'Compile Error',
    12=> 'Test Completed',
    13=> 'Skipped',
    14=> 'System Error',
    15=> 'Submitted'
];
```
## Waiting (等待中)

你的代码已经成功提交，但判题服务还没有开始处理你的提交。

## Queueing (队列中)

判题服务已经获取到你的提交，正在队列中等待评测。

## Compiling (编译中)

你的代码已经进入了编译阶段。如果你提交的是`Python`等无需编译的、解释型语言，将不会经过编译阶段。

## Running (运行中)

你的代码编译成功，开始运行。运行的次数取决于出题人提供的测试数据组数。

对于每一组测试数据，你的程序将会被运行1次，标准输出将会进行答案比对。答案比对有两种方式：
1. 文本对比；你的标准输出和标注答案进行逐字符比较，如不一致，则判为`Wrong Answer`（答案错误）或其他错误类型；
2. 特判对比；出题人提供特判程序来评测你的标准输出，评测结果取决于出题人的特判程序；具体可参考[特判方法](./spj.md)；

## Accepted (正确)

你提交的程序运行通过了所有的测试数据。

## Presentation Error (格式错误)

你的程序运行输出的结果已经非常接近正确答案，但由于空格、回车等空白符输出有误，不能通过评测。

## Wrong Answer (答案错误)

你提交的程序没有通过所有的测试数据。请检查你的代码，充分考虑可能遇到的错误，如边界判断、数据类型不适当等。

## Time Limit Exceeded (时间超限)

你的程序没有在出题人规定的时间内运行输出结果。请检查是否存在死循环、算法复杂度过高等问题。

请注意，对于多组测试数据，判题服务将运行时间最长的测试组所使用的CPU时间作为你的程序运行时间。

## Memory Limit Exceeded (内存超限)

你的程序所使用的内存空间超出了题目的限制，请优化空间复杂度。


## Output Limit Exceeded (输出超限)

你的程序输出的过多的内容，以至于判题服务不能继续读取你的标准输出。请检查是否存在调试期间写的输出语句没有注释掉。

## Runtime Error (运行崩溃)

运行崩溃发生的情况有很多，你需要检查代码细节。
- 除法发生了除零操作等未定义行为；
- 访问了非法的内存空间，例如数组越界、野指针内存泄漏等；
- 申请了过多的内存空间，以至于判题服务无法执行你的程序；
- 调用了高危、敏感的系统调用（如`system`）而被判题服务禁止；

## Compile Error (编译错误)

可能的原因：

- 你的代码存在语法错误，请先在本地运行通过再提交；
- 你在提交时选错了编程语言；
- 你的本地编译器不符合国际通用编译标准；

## System Error (系统错误)

判题服务存在错误，可能是出题人没有提供测试数据。
