<?php namespace FluentGitDb\QueryBuilder\Adapters;

class Mysql extends BaseAdapter
{
    /**
     * @var string
     */
    protected $sanitizer = '`';
}
