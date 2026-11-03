<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A hand-made catalog record linked to the reference product it was added
 * from. See the create_reference_links_table migration.
 */
#[Fillable(['reference_item_id', 'linkable_type', 'linkable_id', 'stable_key'])]
class ReferenceLink extends Model {}
