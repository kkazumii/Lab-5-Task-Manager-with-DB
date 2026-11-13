/* =========================================================
   TaskFlow — script.js  
 
   EVENTS USED:
     1. input    -> live search filtering
     2. click    -> open/close Add Task form, toggle Edit forms,
                    star a task, delete a task, filter by status
     3. change   -> checkbox marks a task done/undone
     4. keydown  -> Escape closes open forms
   ========================================================= */
 
/* short helpers for selecting elements */
const $ = (selector, parent = document) => parent.querySelector(selector);
const $$ = (selector, parent = document) => [...parent.querySelectorAll(selector)];
 
document.addEventListener("DOMContentLoaded", function () {
 
  const taskList = $("#task-list");
 
  /* ---------- 1. Live Search Filtering ---------- */
 
  /* FUNCTION 1 — searchTasks()                     [input] */
  function searchTasks(event) {
    const filter = event.target.value.trim().toLowerCase();
    let visibleCount = 0;
 
    $$(".task-item").forEach(function (task) {
      const text = $(".task-details", task).textContent.toLowerCase();
      const isMatch = text.includes(filter);
      task.style.display = isMatch ? "" : "none";
      if (isMatch) {
        visibleCount++;
      }
    });
 
    // Show a message when no task matches the search
    $("#no-results").hidden = visibleCount > 0;
  }
  $("#task-search").addEventListener("input", searchTasks);
 
  /* ---------- 2. Add Task Form ---------- */
  const addTaskBtn = $("#add-task-btn");
  const addTaskSection = $("#add-task-section");

  /* Helper: closes all inline Edit forms so only one form is open at a time */
  function closeAllEditForms() {
    $$(".edit-form").forEach(function (form) {
      form.hidden = true;
    });
    $$(".btn-icon.edit").forEach(function (btn) {
      btn.setAttribute("aria-expanded", "false");
    });
  }

  /* FUNCTION 2 — toggleAddForm()                   [click] */
  function toggleAddForm() {
    const willOpen = addTaskSection.hidden;
    if (willOpen) {
      closeAllEditForms(); // close any open Edit forms when opening Add Task
    }
    addTaskSection.hidden = !willOpen;
    addTaskBtn.setAttribute("aria-expanded", willOpen);
  }
  addTaskBtn.addEventListener("click", toggleAddForm);

  /* FUNCTION 3 — closeAddForm()                    [click] */
  function closeAddForm() {
    addTaskSection.hidden = true;
    addTaskBtn.setAttribute("aria-expanded", "false");
  }
  $("#cancel-task-btn").addEventListener("click", closeAddForm);

  /* ---------- 3. Inline Edit Forms ---------- */

  /* FUNCTION 4 — toggleEditForm()                  [click] */
  function toggleEditForm(event) {
    const editBtn = event.target.closest(".btn-icon.edit");
    if (!editBtn) {
      return;
    }
    const editForm = $(".edit-form", editBtn.closest(".task-item"));
    const willOpen = editForm.hidden;

    // Close the Add form and any other open Edit forms first
    closeAddForm();
    closeAllEditForms();

    editForm.hidden = !willOpen;
    editBtn.setAttribute("aria-expanded", willOpen);
  }
  taskList.addEventListener("click", toggleEditForm);
 
  /* ---------- 4. Star + Mark Done ---------- */
 
  /* FUNCTION 6 — toggleStar()                      [click] */
  function toggleStar(event) {
    const starBtn = event.target.closest(".btn-icon.star");
    if (!starBtn) {
      return;
    }
    const isActive = starBtn.classList.toggle("is-active");
    starBtn.textContent = isActive ? "★" : "☆";
    starBtn.setAttribute("aria-pressed", isActive);
  }
  taskList.addEventListener("click", toggleStar);
 
  /* FUNCTION 7 — toggleDone()                      [change] */
  function toggleDone(event) {
    if (!event.target.classList.contains("task-checkbox")) {
      return;
    }
    const taskItem = event.target.closest(".task-item");
    taskItem.classList.toggle("is-complete", event.target.checked);
    $("h3", taskItem).classList.toggle("is-done", event.target.checked);
    event.target.form.submit();   // sends toggle_done to PHP
  }
  taskList.addEventListener("change", toggleDone);
 
  /* ---------- 5. Confirm Modal (Move to Trash / Delete permanently / Empty Trash) ---------- */
  /* Any button with the class "js-confirm" opens this modal. The button carries the wording in
     data-modal-title, data-modal-message and data-modal-confirm. When the user confirms,
     the form that the button sits in is submitted to PHP. */
  const deleteModal = $("#delete-modal");
  let formToSubmit = null;
 
  /* FUNCTION 8 — openDeleteModal()                 [click] */
  function openDeleteModal(event) {
    const trigger = event.target.closest(".js-confirm");
    if (!trigger) {
      return;
    }
    formToSubmit = trigger.closest("form");
    $("#delete-modal-title").textContent = trigger.dataset.modalTitle;
    $("#delete-modal-message").textContent = trigger.dataset.modalMessage;
    $("#delete-confirm-btn").textContent = trigger.dataset.modalConfirm;
    deleteModal.showModal();
  }
  document.addEventListener("click", openDeleteModal);   // document, because "Empty Trash" is outside the task list
 
  /* FUNCTION 9 — confirmDelete()                   [click] */
  function confirmDelete() {
    const form = formToSubmit;
    formToSubmit = null;
    deleteModal.close();
    if (form) {
      form.submit();   // sends delete / delete_forever / empty_trash to PHP
    }
  }
  $("#delete-confirm-btn").addEventListener("click", confirmDelete);
 
  /* FUNCTION 10 — closeDeleteModal()               [click] */
  function closeDeleteModal(event) {
    if (event.target.id === "delete-cancel-btn" || event.target === deleteModal) {
      formToSubmit = null;
      deleteModal.close();
    }
  }
  $("#delete-cancel-btn").addEventListener("click", closeDeleteModal);
  deleteModal.addEventListener("click", closeDeleteModal);
 
  /* ---------- 6. Status Filter Tabs ---------- */
 
  /* FUNCTION 11 — filterByStatus()                  [click] */
  function filterByStatus(event) {
    event.preventDefault();
    const status = this.dataset.status;
 
    $$(".filter-tab").forEach(function (tab) {
      tab.classList.toggle("active", tab === event.currentTarget);
    });
    $$(".task-item").forEach(function (task) {
      task.hidden = status !== "all" && task.dataset.status !== status;
    });
  };
 
  /* ---------- 7. Keyboard Shortcut ---------- */
 
  /* FUNCTION 12 — closeFormsOnEscape()             [keydown] */
  function closeFormsOnEscape(event) {
    if (event.key !== "Escape") {
      return;
    }
    closeAddForm();
    $$(".edit-form").forEach(function (form) {
      form.hidden = true;
    });
    $$(".btn-icon.edit").forEach(function (btn) {
      btn.setAttribute("aria-expanded", "false");
    });
  }
  document.addEventListener("keydown", closeFormsOnEscape);

  /* ---------- 8. Live Character Counters (Title & Description) ---------- */

  /* FUNCTION 13 — initCharCounters()               [input] */
  function initCharCounters() {
    $$(".task-form input[maxlength]").forEach(function (input) {
      const max = parseInt(input.getAttribute("maxlength"), 10);
      const field = input.closest(".field");
      const label = field ? $("label", field) : null;
      if (!label || !max) {
        return;
      }

      const counter = document.createElement("span");
      counter.className = "char-count";
      label.appendChild(counter);

      function updateCounter() {
        const len = [...input.value].length; // matches PHP mb_strlen()
        counter.textContent = len + " / " + max;
        counter.classList.toggle("is-max", len >= max);
      }

      updateCounter();
      input.addEventListener("input", updateCounter);
    });
  }
  initCharCounters();
 
});