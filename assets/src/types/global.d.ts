interface Window {
  wpApiSettings: {
    nonce: string;
    root: string;
  }
  // Settings localized by the plugin (php) when enqueuing admin scripts
  erpBudgetingSettings?: {
    currentPage?: string;
    adminUrl?: string;
  }
}