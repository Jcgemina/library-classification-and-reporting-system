(() => {
class BackupManager {
  constructor() {
    this.csrfToken = null;
    this.pendingRestoreId = null;
    this.processing = false;
    this.historyLoaded = false;
    this.historyRequestId = 0;
    this.configureScreen = document.querySelector('[data-configure-page]');
    this.init();
  }

  init() {
    this.csrfToken = this.configureScreen?.dataset.backupCsrfToken || '';

    this.setupEventListeners();
    this.renderHistoryLoading();
    this.loadBackupHistory();
  }

  setupEventListeners() {
    const createBtn = document.getElementById('createBackupButton');
    const fileInput = document.getElementById('backupRestoreFile');
    const previewBtn = document.getElementById('previewRestoreButton');
    const cancelBtn = document.getElementById('cancelBackupRestore');
    const confirmBtn = document.getElementById('confirmBackupRestore');
    const showAllBtn = document.getElementById('viewAllBackupsButton');
    const closeHistoryBtn = document.getElementById('closeBackupHistoryDialog');
    const historyDialog = document.getElementById('backupHistoryDialog');
    const restoreDialog = document.getElementById('backupRestoreDialog');

    if (createBtn) {
      createBtn.addEventListener('click', () => this.createBackup());
    }

    if (fileInput) {
      fileInput.addEventListener('change', (e) => this.onFileSelected(e));
    }

    if (previewBtn) {
      previewBtn.addEventListener('click', () => this.validateRestore());
    }

    if (cancelBtn) {
      cancelBtn.addEventListener('click', () => this.closeRestoreDialog());
    }

    if (confirmBtn) {
      confirmBtn.addEventListener('click', () => this.confirmRestore());
    }

    if (showAllBtn) {
      showAllBtn.addEventListener('click', () => this.openBackupHistory());
    }

    if (closeHistoryBtn) {
      closeHistoryBtn.addEventListener('click', () => this.closeBackupHistory());
    }

    historyDialog?.addEventListener('click', (event) => {
      if (event.target === historyDialog) this.closeBackupHistory();
    });

    restoreDialog?.addEventListener('cancel', (event) => {
      if (this.processing) event.preventDefault();
    });

    document.addEventListener('click', (e) => {
      const actionButton = e.target instanceof Element ? e.target.closest('[data-backup-action]') : null;
      if (actionButton && !this.processing) {
        const action = actionButton.dataset.backupAction;
        const filename = actionButton.dataset.backupFilename;
        if (action === 'download') this.downloadBackup(filename);
        if (action === 'restore') this.prepareRestore(filename);
      }
    });
  }

  beginProcessing(button, label) {
    if (this.processing || !button || button.disabled) return false;

    this.processing = true;
    button.dataset.idleContent = button.innerHTML;
    const spinner = document.createElement('span');
    spinner.className = 'backup-button-spinner';
    spinner.setAttribute('aria-hidden', 'true');
    button.replaceChildren(spinner, document.createTextNode(label));
    button.setAttribute('aria-busy', 'true');
    this.syncActionControls();
    return true;
  }

  endProcessing(button) {
    if (button?.dataset.idleContent !== undefined) {
      button.innerHTML = button.dataset.idleContent;
      delete button.dataset.idleContent;
    }
    button?.removeAttribute('aria-busy');
    this.processing = false;
    this.syncActionControls();
    lucide.createIcons();
  }

  syncActionControls() {
    document.querySelectorAll(
      '#backupRestoreSettings button, #backupHistoryDialog button, #backupRestoreDialog button, #backupRestoreFile'
    ).forEach(control => {
      control.disabled = this.processing;
    });

    if (this.processing) return;

    const fileInput = document.getElementById('backupRestoreFile');
    const previewBtn = document.getElementById('previewRestoreButton');
    const confirmBtn = document.getElementById('confirmBackupRestore');
    if (previewBtn) previewBtn.disabled = !fileInput?.files?.length;
    if (confirmBtn) confirmBtn.disabled = !this.pendingRestoreId;
  }

  async createBackup() {
    const btn = document.getElementById('createBackupButton');
    const status = document.getElementById('backupActionStatus');

    if (!this.beginProcessing(btn, 'Creating backup…')) return;
    status.textContent = 'Creating backup of the database and uploaded files...';
    status.className = 'backup-status';

    try {
      const response = await fetch('pages/backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=create&csrf_token=${encodeURIComponent(this.csrfToken)}`
      });

      const data = await response.json();

      if (data.success) {
        status.textContent = 'Backup created. Download it from Backup history if needed.';
        status.className = 'backup-status is-success';
        await this.loadBackupHistory();
      } else {
        throw new Error(data.message || 'The backup could not be created. Ask your system administrator for help.');
      }
    } catch (error) {
      status.textContent = error.message || 'The backup could not be created. Ask your system administrator for help.';
      status.className = 'backup-status is-error';
    } finally {
      this.endProcessing(btn);
    }
  }

  async loadBackupHistory() {
    const requestId = ++this.historyRequestId;
    try {
      const response = await fetch('pages/backup.php?action=list');
      if (!response.ok) {
        throw new Error('Backup history could not be loaded. Refresh the page to try again.');
      }
      const data = await response.json();

      if (!data.success || !Array.isArray(data.backups)) {
        throw new Error(data.message || 'Backup history could not be loaded. Refresh the page to try again.');
      }
      if (requestId !== this.historyRequestId) return false;
      this.historyLoaded = true;
      this.renderBackupHistory(data.backups);
      return true;
    } catch (error) {
      if (requestId !== this.historyRequestId) return false;
      console.error('Failed to load backup history:', error);
      this.renderBackupHistoryError(error.message || 'Backup history could not be loaded. Refresh the page to try again.');
      const status = document.getElementById('backupActionStatus');
      if (status) {
        status.textContent = error.message || 'Backup history could not be loaded. Refresh the page to try again.';
        status.className = 'backup-status is-error';
      }
      return false;
    }
  }

  renderBackupHistory(backups) {
    const summary = document.getElementById('backupHistorySummary');
    const dialogSummary = document.getElementById('backupHistoryDialogSummary');
    const backupCount = backups.length;
    if (summary) {
      summary.textContent = backupCount > 5
        ? `Showing the 5 most recent of ${backupCount} backups.`
        : `Showing all ${backupCount} saved backup${backupCount === 1 ? '' : 's'}.`;
    }
    if (dialogSummary) {
      dialogSummary.textContent = `${backupCount} saved backup${backupCount === 1 ? '' : 's'}.`;
    }

    this.renderBackupRows(
      document.getElementById('backupHistoryRecentRows'),
      backups.slice(0, 5),
      'No saved backups yet. Create one above to get started.'
    );
    this.renderBackupRows(
      document.getElementById('backupHistoryAllRows'),
      backups,
      'No saved backups yet. Create one above to get started.'
    );
    this.syncActionControls();
    lucide.createIcons();
  }

  renderBackupRows(tbody, backups, emptyMessage) {
    if (!tbody) return;
    tbody.replaceChildren();

    if (backups.length === 0) {
      this.renderHistoryMessage(tbody, emptyMessage);
      return;
    }

    backups.forEach(backup => {
      const row = document.createElement('tr');
      const created = document.createElement('span');
      created.className = 'backup-history-time';
      created.textContent = backup.created;
      row.append(
        this.createHistoryCell('Created', created),
        this.createHistoryCell('Contents', backup.contents),
        this.createHistoryCell('Size', backup.size)
      );

      const statusCell = document.createElement('td');
      statusCell.dataset.label = 'Status';
      const statusText = document.createElement('span');
      statusText.className = 'settings-inline-status';
      const statusIcon = document.createElement('i');
      statusIcon.setAttribute('data-lucide', 'circle-check');
      statusIcon.setAttribute('aria-hidden', 'true');
      statusText.append(statusIcon, document.createTextNode(backup.status));
      statusCell.append(statusText);
      row.append(statusCell);

      const actionsCell = document.createElement('td');
      actionsCell.dataset.label = 'Actions';
      const actions = document.createElement('div');
      actions.className = 'backup-history-actions';
      actions.append(
        this.createActionButton('download', backup.filename, 'Download', 'download'),
        this.createActionButton('restore', backup.filename, 'Restore')
      );
      actionsCell.append(actions);
      row.append(actionsCell);
      tbody.appendChild(row);
    });
  }

  renderHistoryMessage(tbody, message) {
    if (!tbody) return;
    const row = document.createElement('tr');
    const cell = document.createElement('td');
    cell.className = 'backup-history-message';
    cell.dataset.label = 'Backup history';
    cell.colSpan = 5;
    cell.textContent = message;
    row.append(cell);
    tbody.replaceChildren(row);
  }

  renderHistoryLoading() {
    const summary = document.getElementById('backupHistorySummary');
    const dialogSummary = document.getElementById('backupHistoryDialogSummary');
    if (summary) summary.textContent = 'Loading saved backups…';
    if (dialogSummary) dialogSummary.textContent = 'Loading saved backups…';
    this.renderHistoryMessage(document.getElementById('backupHistoryRecentRows'), 'Loading backups…');
    this.renderHistoryMessage(document.getElementById('backupHistoryAllRows'), 'Loading backups…');
  }

  renderBackupHistoryError(message) {
    const summary = document.getElementById('backupHistorySummary');
    const dialogSummary = document.getElementById('backupHistoryDialogSummary');
    if (!this.historyLoaded) {
      if (summary) summary.textContent = 'Backup history could not be loaded.';
      this.renderHistoryMessage(document.getElementById('backupHistoryRecentRows'), message);
    }
    if (dialogSummary) dialogSummary.textContent = message;
    this.renderHistoryMessage(document.getElementById('backupHistoryAllRows'), message);
  }

  async openBackupHistory() {
    const dialog = document.getElementById('backupHistoryDialog');
    if (!dialog || dialog.open || this.processing) return;

    dialog.showModal();
    const summary = document.getElementById('backupHistoryDialogSummary');
    if (summary) summary.textContent = 'Loading saved backups…';
    this.renderHistoryMessage(document.getElementById('backupHistoryAllRows'), 'Loading backups…');
    await this.loadBackupHistory();
  }

  closeBackupHistory() {
    const dialog = document.getElementById('backupHistoryDialog');
    if (dialog?.open) dialog.close();
  }

  createHistoryCell(label, content) {
    const cell = document.createElement('td');
    cell.dataset.label = label;
    if (content instanceof Node) {
      cell.append(content);
    } else {
      cell.textContent = content;
    }
    return cell;
  }

  createActionButton(action, filename, label, iconName = null) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = action === 'restore' ? 'button button-danger' : 'button';
    button.dataset.backupAction = action;
    button.dataset.backupFilename = filename;
    button.title = action === 'restore' ? 'Restore from this backup' : 'Download backup';
    if (iconName) {
      const icon = document.createElement('i');
      icon.setAttribute('data-lucide', iconName);
      icon.setAttribute('aria-hidden', 'true');
      button.append(icon);
    }
    button.append(document.createTextNode(label));
    return button;
  }

  onFileSelected(e) {
    if (this.processing) return;
    const file = e.target.files[0];
    const selectedFile = document.getElementById('backupSelectedFile');
    this.pendingRestoreId = null;

    if (file) {
      selectedFile.textContent = `Selected backup: ${file.name}`;
      selectedFile.hidden = false;
    } else {
      selectedFile.hidden = true;
    }
    this.syncActionControls();
  }

  async validateRestore() {
    const fileInput = document.getElementById('backupRestoreFile');
    const file = fileInput.files[0];
    const status = document.getElementById('backupActionStatus');

    if (!file) {
      status.textContent = 'Choose a backup ZIP first.';
      status.className = 'backup-status is-error';
      return;
    }

    const btn = document.getElementById('previewRestoreButton');
    if (!this.beginProcessing(btn, 'Checking backup…')) return;
    status.textContent = 'Checking the backup file...';
    status.className = 'backup-status';

    try {
      const formData = new FormData();
      formData.append('action', 'validate');
      formData.append('csrf_token', this.csrfToken);
      formData.append('restore_file', file);

      const response = await fetch('pages/backup.php', {
        method: 'POST',
        body: formData
      });

      const data = await response.json();

      if (data.success) {
        this.pendingRestoreId = data.restore_id;
        const dialog = document.getElementById('backupRestoreDialog');
        const selection = document.getElementById('backupRestoreSelection');

        selection.textContent = file.name;
        dialog.showModal();

        status.textContent = '';
      } else {
        throw new Error(data.message || 'The backup could not be checked. Try another ZIP file or ask your system administrator.');
      }
    } catch (error) {
      status.textContent = error.message || 'The backup could not be checked. Try another ZIP file or ask your system administrator.';
      status.className = 'backup-status is-error';
    } finally {
      this.endProcessing(btn);
    }
  }

  prepareRestore(filename) {
    if (this.processing || !filename) return;
    this.closeBackupHistory();
    const dialog = document.getElementById('backupRestoreDialog');
    const selection = document.getElementById('backupRestoreSelection');

    this.pendingRestoreId = filename;
    selection.textContent = filename;
    dialog.showModal();
    this.syncActionControls();
    document.getElementById('confirmBackupRestore').focus();
  }

  async confirmRestore() {
    if (!this.pendingRestoreId) {
      const status = document.getElementById('backupActionStatus');
      status.textContent = 'Choose a backup to restore first.';
      status.className = 'backup-status is-error';
      return;
    }

    const btn = document.getElementById('confirmBackupRestore');
    const status = document.getElementById('backupActionStatus');

    if (!this.beginProcessing(btn, 'Restoring backup…')) return;
    status.textContent = 'Restoring the database and uploaded files...';
    status.className = 'backup-status';

    let reloadPending = false;
    try {
      const response = await fetch('pages/backup.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=restore&restore_id=${encodeURIComponent(this.pendingRestoreId)}&csrf_token=${encodeURIComponent(this.csrfToken)}`
      });

      const data = await response.json();

      if (data.success) {
        status.textContent = data.message;
        status.className = 'backup-status is-success';
        this.closeRestoreDialog();
        reloadPending = true;

        setTimeout(() => {
          window.location.reload();
        }, 2000);
      } else {
        throw new Error(data.message || 'The restore could not be completed. Ask your system administrator to check the server logs before retrying.');
      }
    } catch (error) {
      status.textContent = error.message || 'The restore could not be completed. Ask your system administrator to check the server logs before retrying.';
      status.className = 'backup-status is-error';
    } finally {
      if (!reloadPending) this.endProcessing(btn);
    }
  }

  downloadBackup(filename) {
    if (this.processing || !filename) return;
    const link = document.createElement('a');
    link.href = `pages/backup.php?action=download&file=${encodeURIComponent(filename)}`;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  closeRestoreDialog() {
    const dialog = document.getElementById('backupRestoreDialog');
    if (dialog?.open) dialog.close();
    this.pendingRestoreId = null;
    this.syncActionControls();
  }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    window.backupManager = new BackupManager();
  });
} else {
  window.backupManager = new BackupManager();
}
})();
