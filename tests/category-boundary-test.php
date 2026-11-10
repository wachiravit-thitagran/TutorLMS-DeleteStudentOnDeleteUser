<?php
/** Regression: actual repository must never call Tutor's broad progress helper. */
define('ABSPATH',__DIR__.'/');
function absint($v){return abs((int)$v);}
$GLOBALS['broad_calls']=0;
function tutor_utils(){return new class {public function delete_course_progress($c,$u){++$GLOBALS['broad_calls'];}};}
$GLOBALS['wpdb']=new class {
 public $comments='wp_comments';public $deleted=[];
 public function delete($table,$where,$formats=[]){$this->deleted[]=[$table,$where];return 1;}
};
require dirname(__DIR__).'/includes/class-tutor-data-repository.php';
$r=new Tutor_User_Cleanup_Tutor_Data_Repository();
$r->reset_course_progress(123,456);
if($GLOBALS['broad_calls']!==0){fwrite(STDERR,"FAIL: progress cleanup invoked broad Tutor helper which deletes quiz attempts and add-on data\n");exit(1);}
if($GLOBALS['wpdb']->deleted!==[['wp_comments',['user_id'=>456,'comment_post_ID'=>123,'comment_type'=>'course_completed','comment_agent'=>'TutorLMSPlugin']]]){fwrite(STDERR,"FAIL: course mutation was not narrowly scoped\n");exit(1);}
echo "PASS: progress reset never invokes quiz/add-on cleanup; exact course completion deletion\n";

// Model WordPress' warmed user-meta cache when reading-info is absent. The
// aggregate delete is then a no-op and cannot invalidate a raw SQL mutation.
function wp_cache_delete($id,$group='') { if($group==='user_meta'){unset($GLOBALS['cached_meta'][$id]);}return true; }
function get_user_meta($id,$key,$single=true) { if(!isset($GLOBALS['cached_meta'][$id])){$GLOBALS['cached_meta'][$id]=$GLOBALS['db_meta'][$id]??[];}return $GLOBALS['cached_meta'][$id][$key]??''; }
function delete_user_meta($id,$key) { return false; }
$GLOBALS['db_meta'][456]=['_tutor_completed_lesson_id_123'=>1];
$GLOBALS['wpdb']=new class {
 public $usermeta='wp_usermeta';
 public function prepare($q,...$args){return $q;}
 public function esc_like($v){return $v;}
 public function query($q){$GLOBALS['db_meta'][456]=[];return 1;}
};
if(get_user_meta(456,'_tutor_completed_lesson_id_123')!==1){throw new RuntimeException('Cache fixture did not warm');}
$r->delete_lesson_completions(456);
if(get_user_meta(456,'_tutor_completed_lesson_id_123')!==''){fwrite(STDERR,"FAIL: raw progress deletion left a stale allow in WordPress user_meta cache\n");exit(1);}
echo "PASS: raw lesson deletion invalidates warmed user_meta even without reading-info entry\n";
