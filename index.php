<?php
/* =====================================================================
   index.php (TaskFlow)
   Lab 5: tasks are now stored in MySQL (task_db) through PDO.
   Session storage was removed. Search for "DB:" to see what changed.
   ===================================================================== */

require_once 'db.php';                             // DB: gives us the $pdo connection
date_default_timezone_set('Asia/Manila');          // so "overdue" uses PH time


/* ---------- constants ---------- */
// How a task is DISPLAYED. "overdue" is never saved, it is worked out from the due date.
const STATUS_LABELS = ['todo' => 'Pending', 'overdue' => 'Overdue', 'completed' => 'Completed'];
const STATUS_BADGE  = ['todo' => 'status-pending', 'overdue' => 'status-overdue', 'completed' => 'status-completed'];
// What the Status dropdown offers. ('todo' is the page's name for the DB value 'pending')
const STORED_STATUSES = ['todo' => 'Pending', 'completed' => 'Completed'];
// The sidebar pages: ?view=<key> => page title.
const VIEWS = ['mytasks' => 'My Tasks', 'important' => 'Important'];


/* ---------- helper functions ---------- */

// Makes text safe to print inside HTML (prevents XSS).
function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Reads one POST field as a trimmed string ('' if it is missing).
function postValue(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

// DB: checks that a task with this id exists in the table (prepared statement).
function taskExists(PDO $pdo, string $id): bool {
    if (!ctype_digit($id)) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT id FROM tasks WHERE id = ?");
    $stmt->execute([(int) $id]);
    return $stmt->fetch() !== false;
}

// Counts characters, not bytes, so "ñ" or an emoji counts as 1 (strlen would count more).
function textLength(string $text): int {
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

// Validation rules. Returns a list of error messages (empty list = valid).
function validateTask(string $name, string $description): array {
    $errors = [];

    // Task name: required, 3 to 100 characters
    if ($name === '') {
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


/* ---------- read ?view= and ?status= from the URL ---------- */
// Only accept values from our whitelist, never trust the URL.
$view = $_GET['view'] ?? 'mytasks';
if (!is_string($view) || !array_key_exists($view, VIEWS)) {
    $view = 'mytasks';
}
$pageTitle = VIEWS[$view];

$statusFilter = $_GET['status'] ?? 'todo';
if (!is_string($statusFilter) || ($statusFilter !== 'all' && !array_key_exists($statusFilter, STATUS_LABELS))) {
    $statusFilter = 'todo';
}


/* ---------- handle form submissions (all state-changing actions are POST) ---------- */
$errors = [];   // validation messages go in this array. They are printed in red further down.

// what the forms show again when validation fails (so nobody has to retype)
$action       = '';
$failedEditId = '';
$addValues    = ['name' => '', 'description' => '', 'due' => '', 'status' => 'todo'];
$editValues   = $addValues;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = postValue('action');                 // which form was submitted (hidden input)
    $id     = postValue('task_id');                // which task it applies to (hidden input)
    $saved  = false;                               // set to true when something changed, so we redirect

    // DB: these actions work on ONE existing row, so the id must exist in the table.
    $taskId = null;
    if (in_array($action, ['update', 'toggle_star', 'toggle_done', 'delete'], true)) {
        if (taskExists($pdo, $id)) {
            $taskId = (int) $id;
        } else {
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
                'due'         => cleanDate(postValue('task_due')),
                // only 'todo' or 'completed' can be stored
                'status'      => array_key_exists($status, STORED_STATUSES) ? $status : 'todo',
            ];

            $errors = array_merge($errors, validateTask($input['name'], $input['description']));

            if (empty($errors)) {
                // DB: convert page values to database values ('todo' -> 'pending', '' -> NULL)
                $dbStatus = ($input['status'] === 'completed') ? 'completed' : 'pending';
                $dbDue    = ($input['due'] === '') ? null : $input['due'];

                if ($action === 'add') {
                    // DB: CREATE
                    $stmt = $pdo->prepare(
                        "INSERT INTO tasks (task_name, task_description, due_date, status)
                         VALUES (?, ?, ?, ?)"
                    );
                    $stmt->execute([$input['name'], $input['description'], $dbDue, $dbStatus]);
                } else {
                    // DB: UPDATE
                    $stmt = $pdo->prepare(
                        "UPDATE tasks
                         SET task_name = ?, task_description = ?, due_date = ?, status = ?
                         WHERE id = ?"
                    );
                    $stmt->execute([$input['name'], $input['description'], $dbDue, $dbStatus, $taskId]);
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
            if ($taskId !== null) {
                $stmt = $pdo->prepare("UPDATE tasks SET is_important = NOT is_important WHERE id = ?");
                $stmt->execute([$taskId]);
                $saved = true;
            }
            break;

        case 'toggle_done':                        // checkbox: Pending <-> Completed
            if ($taskId !== null) {
                $stmt = $pdo->prepare(
                    "UPDATE tasks SET status = IF(status = 'completed', 'pending', 'completed') WHERE id = ?"
                );
                $stmt->execute([$taskId]);
                $saved = true;
            }
            break;

        case 'delete':                             // DB: DELETE the row permanently
            if ($taskId !== null) {
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
                $stmt->execute([$taskId]);
                $saved = true;
            }
            break;

    }

    // Redirect after a successful POST so that pressing Refresh does not re-send the form.
    if ($saved) {
        header('Location: index.php?view=' . $view . '&status=' . $statusFilter);
        exit;
    }
}


/* ---------- DB: automatic overdue detection (stored in the status column) ---------- */
$stmt = $pdo->prepare(
    "UPDATE tasks SET status = 'overdue' WHERE due_date < CURRENT_DATE AND status = 'pending'"
);
$stmt->execute();

/* ---------- DB: READ. Fetch every task from MySQL ---------- */
$stmt = $pdo->prepare("SELECT * FROM tasks ORDER BY id ASC");
$stmt->execute();

// Turn each database row into the array shape the page below uses.
$allTasks = [];
foreach ($stmt->fetchAll() as $row) {
    $dbStatus = $row['status'];                                     // pending / completed / overdue
    $allTasks[] = [
        'id'          => (string) $row['id'],
        'name'        => $row['task_name'],
        'description' => $row['task_description'],
        'due'         => $row['due_date'] ?? '',                    // NULL -> ''
        'status'      => ($dbStatus === 'completed') ? 'completed' : 'todo',   // value for the Edit dropdown
        'display'     => ($dbStatus === 'pending') ? 'todo' : $dbStatus,       // todo / overdue / completed
        'important'   => (bool) $row['is_important'],
        'created'     => $row['created_at'],
    ];
}

/* ---------- Pick which tasks to show on this page ---------- */
$visibleTasks = [];
foreach ($allTasks as $task) {
    $show = ($view === 'important') ? $task['important'] : true;
    if ($show && ($statusFilter === 'all' || $task['display'] === $statusFilter)) {
        $visibleTasks[] = $task;
    }
}

/* sidebar counts = unfinished tasks only (completed ones are not counted) */
$counts = ['mytasks' => 0, 'important' => 0];
foreach ($allTasks as $t) {
    if ($t['display'] === 'completed') {
        continue;
    }
    $counts['mytasks']++;
    if ($t['important']) {
        $counts['important']++;
    }
}

/* Live stats for the Right Sidebar Overview */
$stats = ['total' => 0, 'todo' => 0, 'overdue' => 0, 'completed' => 0];
foreach ($allTasks as $t) {
    $stats['total']++;
    $stats[$t['display']]++;
}
$openCount = $stats['todo'] + $stats['overdue'];

/* small values the page below needs */
$addOpen    = ($action === 'add' && !empty($errors));   // reopen the Add form when adding failed
// Where the small forms (star, delete ...) send their POST. Already escaped, print it as is.
$formAction = '?view=' . e($view) . '&amp;status=' . e($statusFilter);
?>

<!--------- HTML STRUCTURE --------------->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> | TaskFlow</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay"></div>

<!--------- LEFT SIDEBAR --------------->
<aside class="sidebar" id="sidebar">
    <div class="logo">
    <img src="assets/logo.png" alt="" class="logo-img">
    <div class="logo-text">
      <span class="logo-title">TaskFlow</span>
      <span class="logo-sub">Small steps. Big progress.</span>
    </div>
  </div>

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

  <button type="button" class="menu-toggle" id="menu-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="Open menu">
    <span></span><span></span><span></span>
  </button>
  
  <header class="app-header">
    <div class="header-title">
      <h1><?= e($pageTitle) ?></h1>
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

  <nav class="filter-tabs" aria-label="Filter tasks by status">
    <span class="filter-label"><img src="assets/filter.png" alt=""> Filter:</span>
    <a href="?view=<?= e($view) ?>&amp;status=todo" class="filter-tab<?= $statusFilter === 'todo' ? ' active' : '' ?>" data-status="todo">Pending</a>
    <a href="?view=<?= e($view) ?>&amp;status=overdue" class="filter-tab<?= $statusFilter === 'overdue' ? ' active' : '' ?>" data-status="overdue">Overdue</a>
    <a href="?view=<?= e($view) ?>&amp;status=completed" class="filter-tab<?= $statusFilter === 'completed' ? ' active' : '' ?>" data-status="completed">Completed</a>
    <a href="?view=<?= e($view) ?>&amp;status=all" class="filter-tab<?= $statusFilter === 'all' ? ' active' : '' ?>" data-status="all">All</a>
  </nav>

  <?php if (!empty($errors)): ?>
    <div role="alert">
      <?php foreach ($errors as $error): ?>
        <p class="form-error"><?= e($error) ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!--------- ADD TASK FORM --------------->
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

    <?php if (empty($visibleTasks)): ?>
      <p class="empty-state">No tasks to show here.</p>
    <?php endif; ?>

    <ul class="task-list" id="task-list">

    <?php foreach ($visibleTasks as $task):
      $id          = e($task['id']);
      $name        = e($task['name']);
      $description = e($task['description']);
      $status      = $task['display'];                 // todo / overdue / completed (calculated)
      $isDone      = ($status === 'completed');
      $isOverdue   = ($status === 'overdue');
      $isStarred   = !empty($task['important']);

       // if THIS task's Edit failed validation, show its form open with what was typed
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

          <div class="task-actions">
              <form method="POST" action="?view=<?= e($view) ?>&amp;status=<?= e($statusFilter) ?>">              <input type="hidden" name="action" value="toggle_star">
              <input type="hidden" name="task_id" value="<?= $id ?>">
              <button type="submit" class="btn-icon star<?= $isStarred ? ' is-active' : '' ?>" aria-label="Mark as important" aria-pressed="<?= $isStarred ? 'true' : 'false' ?>"><?= $isStarred ? '★' : '☆' ?></button>
            </form>

            <button type="button" class="btn-icon edit" aria-label="Edit &quot;<?= $name ?>&quot;" aria-expanded="<?= $editFailed ? 'true' : 'false' ?>"><img src="assets/edit.png" alt=""></button>

            <form method="POST" action="<?= $formAction ?>" class="delete-form">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="task_id" value="<?= $id ?>">
              <button type="button" class="btn-icon js-confirm" aria-label="Delete &quot;<?= $name ?>&quot;"
                      data-modal-title="Delete task?"
                      data-modal-message="&quot;<?= $name ?>&quot; will be permanently deleted. This can't be undone."
                      data-modal-confirm="Delete"><img src="assets/trash.png" alt=""></button>
            </form>
          </div>
        </div>

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

<!-- Confirm modal (script.js): title, message and button text are filled in by script.js -->
<dialog class="modal" id="delete-modal" aria-labelledby="delete-modal-title">
  <h2 id="delete-modal-title">Delete task?</h2>
  <p id="delete-modal-message">This task will be permanently deleted.</p>
  <div class="form-actions">
    <button type="button" class="btn-primary btn-secondary" id="delete-cancel-btn">Cancel</button>
    <button type="button" class="btn-primary btn-danger" id="delete-confirm-btn">Delete</button>
  </div>
</dialog>

<script src="script.js"></script>
</body>
</html>
