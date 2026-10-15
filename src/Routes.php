<?php
declare(strict_types=1);

/**
 * 接口路由表。
 *
 * 注意顺序：字面路径（/recipes/export）必须排在参数路径（/recipes/{id}）前面，
 * 否则「export」会被当成 id 吃掉。
 */

use RH\Api\AdminApi;
use RH\Api\AuthApi;
use RH\Api\IngredientApi;
use RH\Api\MetaApi;
use RH\Api\NotifyApi;
use RH\Api\PantryApi;
use RH\Api\PlanApi;
use RH\Api\RecipeApi;
use RH\Api\ShoppingApi;
use RH\Api\UploadApi;
use RH\Router;

$r = new Router();

// ============================================================ 免登录

$r->guest('POST', '/api/setup', array(AuthApi::class, 'setup'));
$r->guest('POST', '/api/login', array(AuthApi::class, 'login'));
$r->guest('POST', '/api/register', array(AuthApi::class, 'register'));
$r->guest('GET', '/api/bootstrap', array(MetaApi::class, 'bootstrap'));
$r->guest('GET', '/api/units', array(MetaApi::class, 'units'));

// 站点开了「游客浏览」才有内容；没开的话这两个接口自己回 403
$r->guest('GET', '/api/public/recipes', array(RecipeApi::class, 'publicList'));
$r->guest('GET', '/api/public/recipes/{id}', array(RecipeApi::class, 'publicGet'));

// ============================================================ 账号
// 这一组动的是「自己的账号」，不是站内内容，所以用 self：
// 只读成员也该能登出、改自己的密码和资料、收藏菜谱。

$r->self('POST', '/api/logout', array(AuthApi::class, 'logout'));
$r->self('GET', '/api/me', array(AuthApi::class, 'me'));
$r->self('POST', '/api/password', array(AuthApi::class, 'password'));
$r->self('POST', '/api/profile', array(AuthApi::class, 'profile'));
// 数据导出/导入不在这里：整站备份（数据库 + 图片）统一在 /api/admin/backups/*，
// 只有管理员能干，也不再分「我的数据」和「整站备份」两套。

// ============================================================ 菜谱

$r->get('/api/recipes/export', array(RecipeApi::class, 'export'));
$r->post('/api/recipes/import', array(RecipeApi::class, 'import'));
$r->get('/api/recipes/stats', array(RecipeApi::class, 'stats'));
$r->get('/api/recipes', array(RecipeApi::class, 'list'));
$r->post('/api/recipes', array(RecipeApi::class, 'create'));
// 字面路径（random / trash）必须排在 /api/recipes/{id} 之前，
// 否则会被当成 id 吃掉
$r->get('/api/recipes/random', array(RecipeApi::class, 'random'));
$r->get('/api/recipes/trash', array(RecipeApi::class, 'trash'));
$r->post('/api/recipes/trash/{id}/restore', array(RecipeApi::class, 'trashRestore'));
$r->delete('/api/recipes/trash/{id}/purge', array(RecipeApi::class, 'trashPurge'));

// 留言和下厨记录属于「表达」而不是「改内容」：只读成员也能留言、能打卡
$r->self('GET', '/api/recipes/{id}/comments', array(RecipeApi::class, 'comments'));
$r->self('POST', '/api/recipes/{id}/comments', array(RecipeApi::class, 'commentAdd'));
$r->self('DELETE', '/api/comments/{id}', array(RecipeApi::class, 'commentRemove'));
$r->self('GET', '/api/recipes/{id}/cooks', array(RecipeApi::class, 'cooks'));
$r->self('POST', '/api/recipes/{id}/cooks', array(RecipeApi::class, 'cookAdd'));
$r->self('DELETE', '/api/cooks/{id}', array(RecipeApi::class, 'cookRemove'));

$r->get('/api/recipes/{id}', array(RecipeApi::class, 'get'));
$r->put('/api/recipes/{id}', array(RecipeApi::class, 'update'));
$r->delete('/api/recipes/{id}', array(RecipeApi::class, 'remove'));
$r->post('/api/recipes/{id}/duplicate', array(RecipeApi::class, 'duplicate'));
$r->self('POST', '/api/recipes/{id}/favorite', array(RecipeApi::class, 'favorite'));

$r->get('/api/tags', array(RecipeApi::class, 'tags'));
$r->get('/api/cuisines', array(RecipeApi::class, 'cuisines'));

// ============================================================ 食材库

$r->get('/api/ingredients', array(IngredientApi::class, 'list'));
$r->post('/api/ingredients', array(IngredientApi::class, 'create'));
$r->post('/api/ingredients/merge', array(IngredientApi::class, 'merge'));
$r->post('/api/ingredients/refresh', array(IngredientApi::class, 'refresh'));
$r->get('/api/ingredient-categories', array(IngredientApi::class, 'categories'));
$r->put('/api/ingredients/{id}', array(IngredientApi::class, 'update'));
$r->delete('/api/ingredients/{id}', array(IngredientApi::class, 'remove'));

// ============================================================ 家庭库存

$r->get('/api/pantry', array(PantryApi::class, 'list'));
$r->get('/api/pantry/stats', array(PantryApi::class, 'stats'));
$r->get('/api/pantry/suggest', array(PantryApi::class, 'suggest'));
$r->post('/api/pantry', array(PantryApi::class, 'create'));
$r->post('/api/pantry/{id}/adjust', array(PantryApi::class, 'adjust'));
$r->put('/api/pantry/{id}', array(PantryApi::class, 'update'));
$r->delete('/api/pantry/{id}', array(PantryApi::class, 'remove'));

// ============================================================ 膳食计划

$r->get('/api/plan/week', array(PlanApi::class, 'week'));
$r->get('/api/plan/templates', array(PlanApi::class, 'templates'));
$r->get('/api/plan/preview', array(PlanApi::class, 'preview'));
$r->get('/api/plan', array(PlanApi::class, 'range'));
$r->post('/api/plan', array(PlanApi::class, 'create'));
$r->post('/api/plan/copy', array(PlanApi::class, 'copy'));
$r->post('/api/plan/clear', array(PlanApi::class, 'clear'));
$r->post('/api/plan/to-shopping', array(PlanApi::class, 'toShopping'));
$r->post('/api/plan/templates', array(PlanApi::class, 'templateCreate'));
$r->post('/api/plan/templates/{id}/apply', array(PlanApi::class, 'templateApply'));
$r->delete('/api/plan/templates/{id}', array(PlanApi::class, 'templateRemove'));
$r->put('/api/plan/{id}', array(PlanApi::class, 'update'));
$r->put('/api/plan/{id}/move', array(PlanApi::class, 'move'));
$r->post('/api/plan/{id}/shuffle', array(PlanApi::class, 'shuffle'));
$r->delete('/api/plan/{id}', array(PlanApi::class, 'remove'));

// ============================================================ 采购清单

$r->get('/api/shopping', array(ShoppingApi::class, 'lists'));
$r->post('/api/shopping', array(ShoppingApi::class, 'create'));
$r->get('/api/shopping/{id}/export', array(ShoppingApi::class, 'export'));
$r->get('/api/shopping/{id}', array(ShoppingApi::class, 'get'));
$r->put('/api/shopping/{id}', array(ShoppingApi::class, 'update'));
$r->delete('/api/shopping/{id}', array(ShoppingApi::class, 'remove'));
$r->post('/api/shopping/{id}/items', array(ShoppingApi::class, 'addItem'));
$r->post('/api/shopping/{id}/from-plan', array(ShoppingApi::class, 'fromPlan'));
$r->post('/api/shopping/{id}/add-recipes', array(ShoppingApi::class, 'addRecipes'));
$r->post('/api/shopping/{id}/tidy', array(ShoppingApi::class, 'tidy'));
$r->post('/api/shopping/{id}/clear-checked', array(ShoppingApi::class, 'clearChecked'));
$r->post('/api/shopping/{id}/uncheck-all', array(ShoppingApi::class, 'uncheckAll'));
$r->post('/api/shopping/{id}/clear', array(ShoppingApi::class, 'clearAll'));
$r->post('/api/shopping/{id}/to-pantry', array(ShoppingApi::class, 'toPantry'));

$r->put('/api/shopping/items/{itemId}', array(ShoppingApi::class, 'updateItem'));
$r->put('/api/shopping/items/{itemId}/toggle', array(ShoppingApi::class, 'toggleItem'));
$r->delete('/api/shopping/items/{itemId}', array(ShoppingApi::class, 'removeItem'));

// ============================================================ 推送通知
// 全是管理员接口：配置里躺着各家平台的密钥，不能给普通成员看。

$r->get('/api/admin/notify/channels', array(NotifyApi::class, 'channels'));
$r->post('/api/admin/notify/channels', array(NotifyApi::class, 'channelSave'));
$r->post('/api/admin/notify/channels/test', array(NotifyApi::class, 'channelTest'));
$r->delete('/api/admin/notify/channels/{id}', array(NotifyApi::class, 'channelRemove'));
$r->post('/api/admin/notify/schedule', array(NotifyApi::class, 'scheduleSave'));
$r->post('/api/admin/notify/run', array(NotifyApi::class, 'runNow'));
$r->get('/api/admin/notify/logs', array(NotifyApi::class, 'logs'));
$r->post('/api/admin/notify/logs/clear', array(NotifyApi::class, 'logClear'));

// ============================================================ 图片上传

$r->post('/api/upload', array(UploadApi::class, 'upload'));

// ============================================================ 管理后台

$r->get('/api/admin/dashboard', array(AdminApi::class, 'dashboard'));
$r->get('/api/admin/users', array(AdminApi::class, 'users'));
$r->post('/api/admin/users', array(AdminApi::class, 'userCreate'));
$r->get('/api/admin/settings', array(AdminApi::class, 'settings'));
$r->post('/api/admin/settings', array(AdminApi::class, 'settingsSave'));
$r->get('/api/admin/logs', array(AdminApi::class, 'logs'));
$r->post('/api/admin/logs/clear', array(AdminApi::class, 'logsClear'));
$r->get('/api/admin/backups', array(AdminApi::class, 'backupList'));
$r->post('/api/admin/backups', array(AdminApi::class, 'backupCreate'));
$r->get('/api/admin/backups/download', array(AdminApi::class, 'backupDownload'));
$r->post('/api/admin/backups/remove', array(AdminApi::class, 'backupRemove'));
// 站内恢复：先体检（inspect）再动手（restore），不用再敲命令行
$r->post('/api/admin/backups/inspect', array(AdminApi::class, 'backupInspect'));
$r->post('/api/admin/backups/restore', array(AdminApi::class, 'backupRestore'));
$r->post('/api/admin/maintenance', array(AdminApi::class, 'maintenance'));
$r->put('/api/admin/users/{id}', array(AdminApi::class, 'userUpdate'));
$r->put('/api/admin/users/{id}/password', array(AdminApi::class, 'userPassword'));
$r->delete('/api/admin/users/{id}', array(AdminApi::class, 'userRemove'));

return $r;
