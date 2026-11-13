<?php
/* =====================================================================
   index.php (TaskFlow)
   Backend part 1 (Prixy): CREATE, READ, UPDATE, star, checkbox.
   Search for "PHP ADDED" to see what the backend added.
   Search for "PAUL" to see where his features plug in.
   ===================================================================== */

session_start();                                   // must come first, before any HTML
date_default_timezone_set('Asia/Manila');          // so "overdue" uses PH time
$_SESSION['tasks'] = $_SESSION['tasks'] ?? [];     // create the list only if it doesn't exist yet


/* DEFAULT TASKS: added once per session*/
if (empty($_SESSION['seeded'])) {
    $_SESSION['tasks'] = [
        [
            'id'          => uniqid(),
            'name'        => 'Prepare presentation',
            'description' => 'Slides for Friday class',
            'due'         => date('Y-m-d', strtotime('+3 days')),   // Pending
            'status'      => 'todo',
            'important'   => true,
            'deleted'     => false,
            'created'     => date('Y-m-d'),
        ],
        [
            'id'          => uniqid(),
            'name'        => 'Finish Chapter 2',
            'description' => 'Read and take notes',
            'due'         => date('Y-m-d', strtotime('-2 days')),   // Overdue
            'status'      => 'todo',
            'important'   => false,
            'deleted'     => false,
            'created'     => date('Y-m-d'),
        ],
        [
            'id'          => uniqid(),
            'name'        => 'Submit lab activity',
            'description' => 'Upload to Google Drive',
            'due'         => date('Y-m-d', strtotime('-1 day')),
            'status'      => 'completed',                           // Completed
            'important'   => false,
            'deleted'     => false,
            'created'     => date('Y-m-d'),
        ],
    ];
    $_SESSION['seeded'] = true;
}

/* ---------- PHP ADDED 1: constants (like C# const) ---------- */
// How a task is DISPLAYED. "overdue" is never saved, it is worked out from the due date.
const STATUS_LABELS = ['todo' => 'Pending', 'overdue' => 'Overdue', 'completed' => 'Completed'];
const STATUS_BADGE  = ['todo' => 'status-pending', 'overdue' => 'status-overdue', 'completed' => 'status-completed'];
// What is actually SAVED in $_SESSION (and what the Status dropdown offers).
const STORED_STATUSES = ['todo' => 'Pending', 'completed' => 'Completed'];
// The sidebar pages: ?view=<key> => page title.
const VIEWS = ['mytasks' => 'My Tasks', 'important' => 'Important', 'trash' => 'Trash'];


/* ---------- PHP ADDED 2: helper functions ---------- */
// A PHP function cannot see variables outside of it (unlike C# fields).
// Anything it needs must be passed in as a parameter, or be a constant.

// Makes text safe to print inside HTML. Like HtmlEncode in C#.
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Reads one POST field as a trimmed string ('' if it is missing).
function postValue(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

// Finds a task's position in the list by its id. Returns null if not found.
function findTaskIndex(array $tasks, string $id) {
    foreach ($tasks as $i => $task) {
        if ($task['id'] === $id) {
            return $i;
        }
    }
    return null;
}

/* ---------- PAUL ADDED 1: validation helpers ---------- */
// Counts characters, not bytes, so "ñ" or an emoji counts as 1 (strlen would count more).
function textLength(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}
 
// The Day 2 rules. Takes the already-trimmed values and returns a list of error messages.
// An empty list means the input is valid.
// Each field shows at most ONE message: "required" is checked first, then the length.
function validateTask(string $name, string $description): array {
    $errors = [];
 
    // Task name: required, 3 to 100 characters
    if ($name === '') {                               // === '' on purpose: empty("0") is true in PHP
        $errors[] = 'Task name is required.';
    } elseif (textLength($name) < 3) {
        $errors[] = 'Task name must be at least 3 characters long.';
    } elseif (textLength($name) > 100) {
        $errors[] = 'Task name cannot exceed 100 characters.';
    }
 
    // Task description: required, at most 255 characters
    if ($description === '') {
        $errors[] = 'Task description is required.';
    } elseif (textLength($description) > 255) {
        $errors[] = 'Task description cannot exceed 255 characters.';
    }
 
    return $errors;
}
 
// The date input always sends YYYY-MM-DD, but a POST can be edited by hand.
// Anything that is not a real date is saved as "no due date".
function cleanDate(string $value): string {
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $value : '';
}

/* ---------- PHP ADDED 3: read ?view= and ?status= from the URL ---------- */
// Only accept values from our whitelist, never trust the URL.
$view = $_GET['view'] ?? 'mytasks';
if (!is_string($view) || !array_key_exists($view, VIEWS)) {
    $view = 'mytasks';
}
$pageTitle = VIEWS[$view];

// PAUL ADDED 2: Trash shows every status by default, so a completed task you deleted is not hidden there
$statusFilter = $_GET['status'] ?? ($view === 'trash' ? 'all' : 'todo');
if (!is_string($statusFilter) || ($statusFilter !== 'all' && !array_key_exists($statusFilter, STATUS_LABELS))) {
    $statusFilter = ($view === 'trash') ? 'all' : 'todo';
}


/* ---------- PHP ADDED 4: handle form submissions ---------- */
$errors = [];   // PAUL: validation messages go in this array. They are printed in red further down.

// PAUL ADDED 3: what the forms show again when validation fails (so nobody has to retype)
$action       = '';
$failedEditId = '';
$addValues    = ['name' => '', 'description' => '', 'due' => '', 'status' => 'todo'];
$editValues   = $addValues;

// PAUL ADDED 4: request guarding. Everything below only runs for a POST request.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postValue('action');                 // which form was submitted (hidden input)
    $id     = postValue('task_id');                // which task it applies to (hidden input)
    $saved  = false;                               // set to true when something changed, so we redirect

     // PAUL ADDED 5: these actions work on ONE existing task, so the id must exist (Day 2 rule).
    // Add / empty_trash do not need an id, so they skip this check.
    $index = null;
    if (in_array($action, ['update', 'toggle_star', 'toggle_done', 'delete', 'restore', 'delete_forever'], true)) {
        $index = findTaskIndex($_SESSION['tasks'], $id);
        if ($index === null) {
            $errors[] = 'Task not found.';
        }
    }

    switch ($action) {

        case 'add':                                // CREATE
        case 'update':                             // UPDATE (reads the same fields as add)
            $status = postValue('task_status');
            $input = [
                'name'        => postValue('task_name'),
                'description' => postValue('task_description'),
                'due'         => postValue('task_due'),
                // keep the saved value clean: only 'todo' or 'completed' can be stored
                'status'      => array_key_exists($status, STORED_STATUSES) ? $status : 'todo',
            ];

            // PAUL ADDED 6: validation. Delete / toggle / star never come through here.
            $errors = array_merge($errors, validateTask($input['name'], $input['description']));

            if (empty($errors)) {
                if ($action === 'add') {
                    $_SESSION['tasks'][] = $input + [
                        'id'        => uniqid(),   // unique id so Edit/Delete hit the right task
                        'important' => false,
                        'deleted'   => false,
                        'created'   => date('Y-m-d'),
                    ];
                } else {
                    // overwrite only name/description/due/status, keep the other keys
                    $_SESSION['tasks'][$index] = array_merge($_SESSION['tasks'][$index], $input);
                }
                $saved = true;
            } elseif ($action === 'add') {
                $addValues = $input;               // refill the Add form
            } else {
                $failedEditId = $id;               // reopen and refill this task's Edit form
                $editValues   = $input;
            }
            break;

        case 'toggle_star':                        // star on/off. Starred tasks show in Important
            if ($index !== null) {
                $_SESSION['tasks'][$index]['important'] = !($_SESSION['tasks'][$index]['important'] ?? false);
                $saved = true;
            }
            break;

        case 'toggle_done':                        // checkbox: Pending <-> Completed
            if ($index !== null) {
                $current = $_SESSION['tasks'][$index]['status'] ?? 'todo';
                $_SESSION['tasks'][$index]['status'] = ($current === 'completed') ? 'todo' : 'completed';
                $saved = true;
            }
            break;

        case 'delete':                             // PAUL ADDED 7: DELETE = move to Trash (the task is kept, just flagged)
            if ($index !== null) {
                $_SESSION['tasks'][$index]['deleted'] = true;
                $saved = true;
            }
            break;
 
        case 'restore':                            // PAUL ADDED 8: bring a task back from Trash
            if ($index !== null) {
                $_SESSION['tasks'][$index]['deleted'] = false;
                $saved = true;
            }
            break;
 
        case 'delete_forever':                     // PAUL ADDED 9: remove ONE task for good. Only allowed from Trash
            if ($index !== null) {
                if (!empty($_SESSION['tasks'][$index]['deleted'])) {
                    unset($_SESSION['tasks'][$index]);
                    $_SESSION['tasks'] = array_values($_SESSION['tasks']);   // re-number the list
                    $saved = true;
                } else {
                    $errors[] = 'Only tasks in Trash can be deleted permanently.';
                }
            }
            break;
 
        case 'empty_trash':                        // PAUL ADDED 10: remove EVERY task that is in Trash for good
            $_SESSION['tasks'] = array_values(array_filter(
                $_SESSION['tasks'],
                fn($task) => empty($task['deleted'])
            ));
            $saved = true;
            break;
    
    }

    // Redirect after a successful POST so that pressing Refresh does not re-send the form.
    if ($saved) {
        // PAUL ADDED 19: always keep the current tab. Before, "All" was dropped and the page jumped back to Pending.
        header('Location: index.php?view=' . $view . '&status=' . $statusFilter);
        exit;
    }
}


/* ---------- PHP ADDED 5: READ. Pick which tasks to show on this page ---------- */
$today        = date('Y-m-d');
$visibleTasks = [];
foreach ($_SESSION['tasks'] as $task) {
    // fill in any missing keys with defaults (the + operator keeps existing values)
    $task += ['due' => '', 'status' => 'todo', 'important' => false, 'deleted' => false];

    // Work out how the task is shown. Overdue is NOT saved: it is calculated every time,
    // like a C# property:  IsOverdue => !Done && Due < Today
    if ($task['status'] === 'completed') {
        $task['display'] = 'completed';
    } elseif ($task['due'] !== '' && $task['due'] < $today) {
        $task['display'] = 'overdue';
    } else {
        $task['display'] = 'todo';
    }

    $show = match ($view) {
        'important' => $task['important'] && !$task['deleted'],
        'trash'     => $task['deleted'],
        default     => !$task['deleted'],          // My Tasks
    };

    if ($show && ($statusFilter === 'all' || $task['display'] === $statusFilter)) {
        $visibleTasks[] = $task;
    }
}

/* PHP ADDED: sidebar counts = unfinished tasks only (completed ones are not counted) */
$counts = ['mytasks' => 0, 'important' => 0, 'trash' => 0];
foreach ($_SESSION['tasks'] as $t) {
    if (!empty($t['deleted'])) {
        $counts['trash']++;
        continue;
    }
    if (($t['status'] ?? 'todo') === 'completed') {
        continue;                                  // completed: not counted anywhere
    }
    $counts['mytasks']++;
    if (!empty($t['important'])) {
        $counts['important']++;
    }
}

/* PAUL: Calculate live stats for the Right Sidebar Overview (non-deleted tasks only) */
$stats = ['total' => 0, 'todo' => 0, 'overdue' => 0, 'completed' => 0];
foreach ($_SESSION['tasks'] as $t) {
    if (!empty($t['deleted'])) {
        continue;
    }
    $stats['total']++;
    $tStatus = $t['status'] ?? 'todo';
    $tDue    = $t['due'] ?? '';

    if ($tStatus === 'completed') {
        $stats['completed']++;
    } elseif ($tDue !== '' && $tDue < $today) {
        $stats['overdue']++;
    } else {
        $stats['todo']++;
    }
}
$openCount = $stats['todo'] + $stats['overdue'];

/* PAUL ADDED 11: small values the page below needs */
$inTrash    = ($view === 'trash');
$addOpen    = ($action === 'add' && !empty($errors));   // reopen the Add form when adding failed
// Where the small forms (star, delete, restore ...) send their POST. Already escaped, print it as is.
$formAction = '?view=' . e($view) . '&amp;status=' . e($statusFilter);
?>

<!--------- HTML STRUCTURE --------------->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) /* PHP ADDED 6 */ ?> | TaskFlow</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<!--------- LEFT SIDEBAR --------------->
<aside class="sidebar">
  <div class="logo">
    <img src="assets/logo.png" alt="" class="logo-img">
    <div class="logo-text">
      <span class="logo-title">TaskFlow</span>
      <span class="logo-sub">Small steps. Big progress.</span>
    </div>
  </div>

  <?php /* PHP ADDED 7: sidebar links use ?view= and the current page gets the "active" class */ ?>
  <nav aria-label="Task categories">
    <ul class="nav-menu">
      <li>
  <a href="?view=mytasks" class="nav-item<?= $view === 'mytasks' ? ' active' : '' ?>"<?= $view === 'mytasks' ? ' aria-current="page"' : '' ?>>
    <img src="assets/tasks.png" alt="" class="nav-icon">
    <span>My Tasks</span>
    <span class="nav-count"><?= $counts['mytasks'] ?></span>
  </a>
      </li>
      <li>
        <a href="?view=important" class="nav-item<?= $view === 'important' ? ' active' : '' ?>"<?= $view === 'important' ? ' aria-current="page"' : '' ?>>
          <img src="assets/Brown Star Outline.png" alt="" class="nav-icon">
          <span>Important</span>
          <span class="nav-count"><?= $counts['important'] ?></span>
        </a>
      </li>
      <li>
        <a href="?view=trash" class="nav-item<?= $view === 'trash' ? ' active' : '' ?>"<?= $view === 'trash' ? ' aria-current="page"' : '' ?>>
          <img src="assets/trash.png" alt="" class="nav-icon">
          <span>Trash</span>
          <span class="nav-count"><?= $counts['trash'] ?></span>
        </a>
      </li>
    </ul>
  </nav>

  <footer class="user">
    <img src="assets/taskflow_user_icon.png" alt="User avatar" class="user-avatar">
    <span class="user-name">ZenaidaInk</span>
    <img src="assets/taskflow_dropdown_icon.png" alt="" class="user-arrow">
  </footer>
</aside>

<!--------- MAIN CONTENT --------------->
<main class="main-content">

  <header class="app-header">
    <div class="header-title">
      <h1><?= e($pageTitle) /* PHP ADDED 6 */ ?></h1>
      <p>What's on the agenda today?</p>
    </div>
    <div class="header-actions">
      <div class="search-bar">
        <img src="assets/Minimal Taupe Magnifying Glass Icon.png" alt="">
        <input type="text" id="task-search" placeholder="Search tasks..." aria-label="Search tasks">
      </div>
      <button type="button" class="btn-primary" id="add-task-btn" aria-controls="add-task-section" aria-expanded="false">
        <img src="assets/add.png" alt=""> Add Task
      </button>
    </div>
  </header>

  <?php /* PHP ADDED 8: the tabs get real links and the current one is marked active */ ?>
  <nav class="filter-tabs" aria-label="Filter tasks by status">
    <span class="filter-label"><img src="assets/filter.png" alt=""> Filter:</span>
    <a href="?view=<?= e($view) ?>&amp;status=todo" class="filter-tab<?= $statusFilter === 'todo' ? ' active' : '' ?>" data-status="todo">Pending</a>
    <a href="?view=<?= e($view) ?>&amp;status=overdue" class="filter-tab<?= $statusFilter === 'overdue' ? ' active' : '' ?>" data-status="overdue">Overdue</a>
    <a href="?view=<?= e($view) ?>&amp;status=completed" class="filter-tab<?= $statusFilter === 'completed' ? ' active' : '' ?>" data-status="completed">Completed</a>
    <a href="?view=<?= e($view) ?>&amp;status=all" class="filter-tab<?= $statusFilter === 'all' ? ' active' : '' ?>" data-status="all">All</a>
  </nav>

  <?php /* PAUL ADDED 12: validation errors are printed here in red (existing .form-error style). All output goes through e() */ ?>
  <?php if (!empty($errors)): ?>
    <div role="alert">
      <?php foreach ($errors as $error): ?>
        <p class="form-error"><?= e($error) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

 <?php /* PAUL ADDED 13: "Empty Trash" button, only on the Trash page and only when there is something in it */ ?>
  <?php if ($inTrash && $counts['trash'] > 0): ?>
    <form method="POST" action="<?= $formAction ?>" class="trash-toolbar">
      <input type="hidden" name="action" value="empty_trash">
      <button type="button" class="btn-text btn-text--danger js-confirm"
              data-modal-title="Empty Trash?"
              data-modal-message="All <?= (int) $counts['trash'] ?> task(s) in Trash will be permanently deleted. This can't be undone."
              data-modal-confirm="Empty Trash">Empty Trash</button>
    </form>
  <?php endif; ?>

  <!--------- ADD TASK FORM --------------->
  <?php /* PHP ADDED 9: CREATE. The form now POSTs to this same page; each input has a name PHP can read */ ?>
  <?php /* PAUL ADDED 14: the form stays open and keeps what was typed when validation fails */ ?>
  <section class="add-task-section" id="add-task-section" aria-labelledby="add-task-title"<?= $addOpen ? '' : ' hidden' ?>>
    <h2 id="add-task-title">Add new task</h2>
    <form class="task-form" method="POST">
      <input type="hidden" name="action" value="add">
      <div class="field"><label for="task_name">Title</label><input type="text" id="task_name" name="task_name" placeholder="e.g. Finish Chapter 2" maxlength="100" value="<?= e($addValues['name']) ?>"></div>
      <div class="field"><label for="task_description">Description</label><input type="text" id="task_description" name="task_description" placeholder="Add a short description" maxlength="255" value="<?= e($addValues['description']) ?>"></div>
      <div class="form-row">
        <div class="field"><label for="task_due">Due date</label><input type="date" id="task_due" name="task_due" value="<?= e($addValues['due']) ?>"></div>
        <div class="field"><label for="task_status">Status</label>
          <select id="task_status" name="task_status">
            <?php foreach (STORED_STATUSES as $value => $label): ?>
              <option value="<?= e($value) ?>"<?= $addValues['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button type="button" class="btn-primary btn-secondary" id="cancel-task-btn">Cancel</button>
        <button type="submit" class="btn-primary">Create task</button>
      </div>
    </form>
  </section>

  <!--------- TASK LIST --------------->
  <section class="tasks" aria-label="Task list">
    <p class="empty-state" id="no-results" hidden>No tasks match your search.</p>

    <?php /* PHP ADDED 10: message when there is nothing to show */ ?>
    <?php if (empty($visibleTasks)): ?>
      <p class="empty-state">No tasks to show here.</p>
    <?php endif; ?>

    <ul class="task-list" id="task-list">

    <?php /* PHP ADDED 11: READ. The loop prints one <li class="task-item"> per task. The 3 sample tasks were removed. */ ?>
    <?php foreach ($visibleTasks as $task):
      $id          = e($task['id']);
      $name        = e($task['name']);
      $description = e($task['description']);
      $status      = $task['display'];                 // todo / overdue / completed (calculated)
      $isDone      = ($status === 'completed');
      $isOverdue   = ($status === 'overdue');
      $isStarred   = !empty($task['important']);

       // PAUL ADDED 15: if THIS task's Edit failed validation, show its form open with what was typed
      $editFailed = ($failedEditId !== '' && $task['id'] === $failedEditId);
      $ev = $editFailed ? $editValues : [
          'name'        => $task['name'],
          'description' => $task['description'],
          'due'         => $task['due'],
          'status'      => $task['status'],
      ];
    ?>
      <li class="task-item<?= $isDone ? ' is-complete' : '' ?>" data-status="<?= e($status) ?>">
        <div class="task-row">

          <?php /* PHP ADDED 12: the checkbox is its own tiny form. Checking it sends action=toggle_done (needs the one-line script.js change) */ ?>
            <form method="POST" action="?view=<?= e($view) ?>&amp;status=<?= e($statusFilter) ?>" class="checkbox-form">            <input type="hidden" name="action" value="toggle_done">
            <input type="hidden" name="task_id" value="<?= $id ?>">
            <input type="checkbox" class="task-checkbox" aria-label="Mark &quot;<?= $name ?>&quot; as done"<?= $isDone ? ' checked' : '' ?>>
          </form>

          <div class="task-details">
            <h3<?= $isDone ? ' class="is-done"' : '' ?>><?= $name ?></h3>
            <p><?= $description ?></p>
          </div>

          <span class="status-badge <?= e(STATUS_BADGE[$status]) ?>"><?= e(STATUS_LABELS[$status]) ?></span>

          <span class="task-date<?= $isOverdue ? ' is-overdue' : '' ?>"><img src="assets/calendar.png" alt=""><?php if ($task['due'] === ''): ?>No due date<?php else: ?><?= e(date('M d, Y', strtotime($task['due']))) ?><?= $isOverdue ? ' (overdue)' : '' ?><?php endif; ?></span>

          <?php /* PAUL: in the Trash view, show Restore / Delete permanently buttons here instead of these icons */ ?>
          <div class="task-actions">
          <?php if ($inTrash): ?>
            <?php /* PAUL ADDED 16: Trash page shows Restore and Delete permanently instead of the icons */ ?>
            <form method="POST" action="<?= $formAction ?>">
              <input type="hidden" name="action" value="restore">
              <input type="hidden" name="task_id" value="<?= $id ?>">
              <button type="submit" class="btn-text">Restore</button>
            </form>
 
            <form method="POST" action="<?= $formAction ?>">
              <input type="hidden" name="action" value="delete_forever">
              <input type="hidden" name="task_id" value="<?= $id ?>">
              <button type="button" class="btn-text btn-text--danger js-confirm"
                      data-modal-title="Delete permanently?"
                      data-modal-message="&quot;<?= $name ?>&quot; will be permanently deleted. This can't be undone."
                      data-modal-confirm="Delete permanently">Delete permanently</button>
            </form>
          <?php else: ?>
            <?php /* PHP ADDED 13: the star is a submit button inside a tiny form. Starred tasks show in Important */ ?>
              <form method="POST" action="?view=<?= e($view) ?>&amp;status=<?= e($statusFilter) ?>">              <input type="hidden" name="action" value="toggle_star">
              <input type="hidden" name="task_id" value="<?= $id ?>">
              <button type="submit" class="btn-icon star<?= $isStarred ? ' is-active' : '' ?>" aria-label="Mark as important" aria-pressed="<?= $isStarred ? 'true' : 'false' ?>"><?= $isStarred ? '★' : '☆' ?></button>
            </form>
 
            <button type="button" class="btn-icon edit" aria-label="Edit &quot;<?= $name ?>&quot;" aria-expanded="<?= $editFailed ? 'true' : 'false' ?>"><img src="assets/edit.png" alt=""></button>
 
            <?php /* PAUL ADDED 17: DELETE. Opens the confirm modal (script.js), then submits this form and the task moves to Trash */ ?>
            <form method="POST" action="<?= $formAction ?>" class="delete-form">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="task_id" value="<?= $id ?>">
              <button type="button" class="btn-icon js-confirm" aria-label="Move &quot;<?= $name ?>&quot; to trash"
                      data-modal-title="Move to Trash?"
                      data-modal-message="&quot;<?= $name ?>&quot; will be moved to Trash. You can restore it later."
                      data-modal-confirm="Move to Trash"><img src="assets/trash.png" alt=""></button>
            </form>
          <?php endif; ?>
          </div>
        </div>
 
        <?php /* PHP ADDED 14: UPDATE. The Edit form is prefilled with this task's values and sends the task's id */ ?>
        <?php if (!$inTrash): ?>
        <form class="task-form edit-form" method="POST"<?= $editFailed ? '' : ' hidden' ?>>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="task_id" value="<?= $id ?>">
          <div class="field"><label for="edit_name_<?= $id ?>">Title</label><input type="text" id="edit_name_<?= $id ?>" name="task_name" value="<?= e($ev['name']) ?>" maxlength="100"></div>
          <div class="field"><label for="edit_desc_<?= $id ?>">Description</label><input type="text" id="edit_desc_<?= $id ?>" name="task_description" value="<?= e($ev['description']) ?>" maxlength="255"></div>
          <div class="form-row">
            <div class="field"><label for="edit_due_<?= $id ?>">Due date</label><input type="date" id="edit_due_<?= $id ?>" name="task_due" value="<?= e($ev['due']) ?>"></div>
            <div class="field"><label for="edit_status_<?= $id ?>">Status</label>
              <select id="edit_status_<?= $id ?>" name="task_status">
                <?php foreach (STORED_STATUSES as $value => $label): ?>
                  <option value="<?= e($value) ?>"<?= $ev['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-actions">
            <button type="button" class="btn-primary btn-secondary">Cancel</button>
            <button type="submit" class="btn-primary">Save changes</button>
          </div>
        </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  </section>
</main>
 
<!--------- RIGHT SIDE BAR --------------->
<aside class="right-sidebar" aria-label="Task overview">
  <h2>Overview</h2>
  <div class="progress-card">
    <?php if ($openCount > 0): ?>
      <p class="progress-value"><?= $openCount ?></p>
      <p class="progress-caption"><?= $openCount === 1 ? 'task left to do' : 'tasks left to do' ?></p>
      <?php if ($stats['overdue'] > 0): ?>
        <a class="overdue-note" href="?view=mytasks&amp;status=overdue"><?= $stats['overdue'] ?> overdue, start here</a>
      <?php endif; ?>
    <?php else: ?>
      <p class="progress-value">All clear</p>
      <p class="progress-caption">Nothing left to do.</p>
    <?php endif; ?>
  </div>
  <ul class="stat-list">
    <li><a class="stat-link<?= $stats['todo'] === 0 ? ' is-zero' : '' ?>" href="?view=mytasks&amp;status=todo"><span class="dot dot-pending"></span><span>Pending</span><strong><?= $stats['todo'] ?></strong></a></li>
    <li><a class="stat-link<?= $stats['overdue'] === 0 ? ' is-zero' : ' is-alert' ?>" href="?view=mytasks&amp;status=overdue"><span class="dot dot-overdue"></span><span>Overdue</span><strong><?= $stats['overdue'] ?></strong></a></li>
    <li><a class="stat-link<?= $stats['completed'] === 0 ? ' is-zero' : '' ?>" href="?view=mytasks&amp;status=completed"><span class="dot dot-completed"></span><span>Completed</span><strong><?= $stats['completed'] ?></strong></a></li>
  </ul>
</aside>
 
<!-- Confirm modal (script.js). PAUL ADDED 18: the title, message and button text are filled in by script.js, so the same modal serves Move to Trash, Delete permanently and Empty Trash -->
<dialog class="modal" id="delete-modal" aria-labelledby="delete-modal-title">
  <h2 id="delete-modal-title">Move to Trash?</h2>
  <p id="delete-modal-message">This task will be moved to Trash. You can restore it later.</p>
  <div class="form-actions">
    <button type="button" class="btn-primary btn-secondary" id="delete-cancel-btn">Cancel</button>
    <button type="button" class="btn-primary btn-danger" id="delete-confirm-btn">Move to Trash</button>
  </div>
</dialog>
 
<script src="script.js"></script>
</body>
</html>