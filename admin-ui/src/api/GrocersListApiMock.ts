import type {
  IGrocersListApi,
  LinkCountInfo,
  MatchedLinks,
  MigrationStatus,
  PostGatingOptions,
  ProcessQueueResult,
  QueueStats,
  ResetFailedResult,
  UrlMapping,
} from './IGrocersListApi';

const STORAGE_KEY = 'grocers_list_mock_state';

export interface GrocersListPluginState {
  apiKey: string;
  useLinkstaLinks: boolean;
}

const getDefaultState = (): GrocersListPluginState => ({
  apiKey: 'mock-api-key-1234567890',
  useLinkstaLinks: true,
});

export class GrocersListApiMock implements IGrocersListApi {
  private delay(ms: number) {
    return new Promise(resolve => setTimeout(resolve, ms));
  }

  private getStateFromStorage(): GrocersListPluginState {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : getDefaultState();
  }

  private setStateToStorage(state: GrocersListPluginState) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
  }

  async updateApiKey(apiKey: string) {
    console.log('🔧 Mock updateApiKey', apiKey);
    await this.delay(1000);
    const state = this.getStateFromStorage();
    state.apiKey = apiKey;
    this.setStateToStorage(state);
  }

  async getState() {
    console.log('🔧 Mock getState');
    await this.delay(1000);
    return this.getStateFromStorage();
  }

  async updateUseLinkstaLinks(enabled: boolean) {
    console.log('🔧 Mock updateUseLinkstaLinks', enabled);
    await this.delay(1000);
    const state = this.getStateFromStorage();
    state.useLinkstaLinks = enabled;
    this.setStateToStorage(state);
  }

  async countMatchedLinks(): Promise<MatchedLinks> {
    console.log('🔧 Mock countMatchedLinks');
    await this.delay(1000);
    return {
      postsWithLinks: 3,
      totalLinks: 42,
    };
  }

  async triggerMigrate(): Promise<void> {
    console.log('🔧 Mock triggerMigrate');
    await this.delay(500);
    localStorage.setItem('grocers_list_mock_migration_started', 'true');
  }

  async clearSettings() {
    console.log('🔧 Mock clearSettings');
    await this.delay(500);
    localStorage.removeItem(STORAGE_KEY);
    localStorage.removeItem('grocers_list_mock_migration_started');
  }

  async getMigrationStatus(): Promise<MigrationStatus> {
    console.log('🔧 Mock getMigrationStatus');
    await this.delay(500);

    const started = localStorage.getItem('grocers_list_mock_migration_started');
    const randomProgress = Math.min(42, Math.floor(Math.random() * 42));
    const isComplete = randomProgress === 42;

    if (!started) {
      return {
        totalPosts: 42,
        processedPosts: 0,
        isComplete: false,
        isRunning: false,
        lastMigrationStartedAt: 0,
        lastMigrationCompletedAt: 0,
        migratedPosts: 0,
        totalMappings: 0,
      };
    }

    return {
      totalPosts: 42,
      processedPosts: isComplete ? 42 : randomProgress,
      isComplete,
      isRunning: false,
      lastMigrationStartedAt: 0,
      lastMigrationCompletedAt: 0,
      migratedPosts: 0,
      totalMappings: 0,
    };
  }

  async getLinkCountInfo(): Promise<LinkCountInfo> {
    console.log('🔧 Mock getLinkCountInfo');
    await this.delay(500);

    return {
      postsWithLinks: 5,
      totalPosts: 25,
      totalAmazonLinks: 50,
      totalMappedLinks: 0,
      totalUnmappedLinks: 0,
    };
  }

  async updatePostGatingOptions(
    postId: number,
    options: PostGatingOptions
  ): Promise<void> {
    console.log('🔧 Mock updatePostGatingOptions', postId, options);
    await this.delay(500);
  }

  async getQueueStats(): Promise<QueueStats> {
    console.log('🔧 Mock getQueueStats');
    await this.delay(400);

    const total = 15;
    const completed = Math.floor(Math.random() * 8);
    const failed = Math.floor(Math.random() * 3);
    const processing = Math.random() > 0.7 ? 1 : 0;
    const pending = total - completed - failed - processing;

    return {
      total,
      pending: Math.max(0, pending),
      processing,
      completed,
      failed,
      nextScheduledRun: new Date(Date.now() + 180000).toISOString(), // 3 minutes from now
    };
  }

  async processQueue(): Promise<ProcessQueueResult> {
    console.log('🔧 Mock processQueue');
    await this.delay(2000); // Simulate processing time

    const processed = Math.floor(Math.random() * 5) + 1;
    const errors = Math.random() > 0.8 ? 1 : 0;

    return { processed, errors };
  }

  async resetFailedPosts(): Promise<ResetFailedResult> {
    console.log('🔧 Mock resetFailedPosts');
    await this.delay(800);

    return { reset: Math.floor(Math.random() * 3) + 1 };
  }

  async getUrlMappings(limit = 100): Promise<UrlMapping[]> {
    console.log('🔧 Mock getUrlMappings', limit);
    await this.delay(600);

    const mappings: UrlMapping[] = [];
    const count = Math.min(limit, Math.floor(Math.random() * 20) + 5);

    for (let i = 0; i < count; i++) {
      mappings.push({
        id: i + 1,
        original_url: `https://amazon.com/dp/B${String(Math.random()).substr(2, 6)}`,
        linksta_url: `https://linksta.io/${String(Math.random()).substr(2, 8)}`,
        link_hash: String(Math.random()).substr(2, 8),
        created_at: new Date(
          Date.now() - Math.random() * 7 * 24 * 60 * 60 * 1000
        ).toISOString(),
      });
    }

    return mappings;
  }

  async updateMembershipsEnabled(enabled: boolean) {
    console.log('🔧 Mock updateMembershipsEnabled', enabled);
    await this.delay(500);
  }
}
