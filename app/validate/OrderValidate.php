<?php
namespace app\validate;
use think\Validate;

class OrderValidate extends Validate
{
    protected $rule = [
        'author' =>  'require|max:25',
        'sn'     =>  'max:40',
        'type'   =>  'max:40',
        'desc'   =>  'max:100'
    ];

    protected $message  =   [
        'author.require' =>  '制单者必填',
        'author.max'     =>  '制单者最多不能超过25个字符',
        'sn.max'         =>  '单号最多不能超过40个字符',
        'type.max'       =>  '类型最多不能超过40个字符',
        'desc.max'       =>  '备注最多不能超过100个字符'
    ];
}
