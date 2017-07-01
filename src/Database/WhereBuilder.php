<?php
/**
 * Created by PhpStorm.
 * User: Frost Wong <frostwong@gmail.com>
 * Date: 29/06/2017
 * Time: 23:51
 */

namespace Hodor\Database;


use Illuminate\Database\Query\Builder;

class WhereBuilder
{
    /**
     * Build where closure for Laravel Query Builder to ease the work
     *
     * @param array $conditions
     * @return \Closure
     */
    public static function build(array $conditions)
    {
        $where = function (Builder $builder) use ($conditions) {
            foreach ($conditions as $condition) {
                call_user_func_array([$builder, 'where'], $condition);
            }
        };

        return $where;
    }
}