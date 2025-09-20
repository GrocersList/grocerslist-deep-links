import type { GrocersListPluginState } from './GrocersListApiMock.ts';

export interface IGrocersListApi {
  updateApiKey(apiKey: string): Promise<void>;
  getState(): Promise<GrocersListPluginState>;
  updateAutoRewrite(enabled: boolean): Promise<void>;
  updateUseLinkstaLinks(enabled: boolean): Promise<void>;
  countMatchedLinks(): Promise<MatchedLinks>;
  triggerMigrate(): Promise<void>;
  clearSettings(): Promise<void>;
  getMigrationStatus(): Promise<MigrationStatus>;
  triggerRecountLinks(): Promise<void>;
  getLinkCountInfo(): Promise<LinkCountInfo>;
  updatePostGatingOptions(
    postId: number,
    options: PostGatingOptions
  ): Promise<void>;
  getQueueStats(): Promise<QueueStats>;
  processQueue(): Promise<ProcessQueueResult>;
  resetFailedPosts(): Promise<ResetFailedResult>;
  getUrlMappings(limit?: number): Promise<UrlMapping[]>;
  updateMembershipsEnabled(enabled: boolean): Promise<void>;
}

export type MatchedLinks = {
  postsWithLinks: number;
  totalLinks: number;
};

export type MigrationStatus = {
  isComplete: boolean;
  isRunning: boolean;
  lastMigrationStartedAt: number;
  lastMigrationCompletedAt: number;
  migratedPosts: number;
  processedPosts: number;
  totalMappings: number;
  totalPosts: number;
};

export type LinkCountInfo = {
  unmappedLinks: number;
  postsWithLinks: number;
  totalLinks: number;
  totalPosts: number;
  lastCount: number;
  isRunning: boolean;
  processedPosts: number;
  isComplete: boolean;
};

export type MigrationResponse = {
  flagged?: number;
  alreadyRunning?: boolean;
  message?: string;
};

export type PostGatingOptions = {
  postGated: boolean;
  recipeCardGated: boolean;
};

export type QueueStats = {
  total: number;
  pending: number;
  processing: number;
  completed: number;
  failed: number;
  nextScheduledRun?: string;
};

export type ProcessQueueResult = {
  processed: number;
  errors: number;
};

export type ResetFailedResult = {
  reset: number;
};

export type UrlMapping = {
  id: number;
  original_url: string;
  linksta_url: string;
  link_hash: string;
  created_at: string;
};
