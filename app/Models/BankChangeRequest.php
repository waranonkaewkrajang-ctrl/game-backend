    <?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class BankChangeRequest extends Model {
    protected $guarded = [];
    protected $casts = ['reviewed_at' => 'datetime'];
    public function user() {
        return $this->belongsTo(User::class);
    }
}